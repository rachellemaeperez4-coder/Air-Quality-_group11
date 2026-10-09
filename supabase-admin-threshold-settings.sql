-- Run after supabase-admin-core.sql and supabase-admin-alerts.sql.
-- Good, Moderate, and Hazardous maxima are publicly readable by ESP32 devices;
-- only active Staff can change them. Values above hazardous_max are Very Hazardous.
begin;

create table if not exists public.aqm_threshold_settings (
  setting_id boolean primary key default true check (setting_id),
  good_max integer not null default 300 check (good_max between 0 and 599),
  moderate_max integer not null default 350 check (moderate_max between 1 and 600 and moderate_max > good_max),
  hazardous_max integer not null default 500 check (hazardous_max between 2 and 600 and hazardous_max > moderate_max),
  updated_at timestamptz not null default now(),
  updated_by uuid references auth.users(id)
);

alter table public.aqm_threshold_settings
  add column if not exists hazardous_max integer not null default 500;

do $$
begin
  if not exists (
    select 1
    from pg_constraint
    where conrelid = 'public.aqm_threshold_settings'::regclass
      and conname = 'aqm_threshold_settings_ranges_check'
  ) then
    alter table public.aqm_threshold_settings
      add constraint aqm_threshold_settings_ranges_check
      check (
        good_max between 0 and 598
        and moderate_max > good_max
        and moderate_max < hazardous_max
        and hazardous_max <= 600
      );
  end if;
end;
$$;

insert into public.aqm_threshold_settings (setting_id, good_max, moderate_max, hazardous_max)
values (true, 300, 350, 500)
on conflict (setting_id) do nothing;

alter table public.aqm_threshold_settings enable row level security;
revoke all on public.aqm_threshold_settings from anon, authenticated;
grant select on public.aqm_threshold_settings to anon, authenticated;

drop policy if exists aqm_thresholds_public_read on public.aqm_threshold_settings;
create policy aqm_thresholds_public_read on public.aqm_threshold_settings
  for select to anon, authenticated
  using (true);

create or replace function public.aqm_staff_update_thresholds(
  p_good_max integer,
  p_moderate_max integer,
  p_hazardous_max integer
)
returns jsonb
language plpgsql
security definer
set search_path = ''
as $$
declare
  updated_settings jsonb;
begin
  if not public.aqm_is_active_staff() then
    raise exception 'Active Staff access is required to update air-quality thresholds.';
  end if;

  if p_good_max is null or p_moderate_max is null or p_hazardous_max is null
    or p_good_max < 0 or p_good_max >= p_moderate_max
    or p_moderate_max >= p_hazardous_max or p_hazardous_max > 600 then
    raise exception 'Thresholds must be whole numbers from 0 to 600 in increasing Good, Moderate, Hazardous order.';
  end if;

  update public.aqm_threshold_settings
  set good_max = p_good_max,
      moderate_max = p_moderate_max,
  hazardous_max = p_hazardous_max,
      updated_at = now(),
      updated_by = auth.uid()
  where setting_id = true
  returning jsonb_build_object(
    'good_max', good_max,
    'moderate_max', moderate_max,
    'hazardous_max', hazardous_max,
    'updated_at', updated_at
  ) into updated_settings;

  if updated_settings is null then
    raise exception 'The air-quality threshold settings row is missing.';
  end if;

  return updated_settings;
end;
$$;

revoke all on function public.aqm_staff_update_thresholds(integer, integer, integer) from public, anon;
grant execute on function public.aqm_staff_update_thresholds(integer, integer, integer) to authenticated;

create or replace function public.aqm_track_hazardous_reading()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  alert_severity text;
  previous_alert_peak numeric;
  configured_good_max integer;
  configured_moderate_max integer;
  configured_hazardous_max integer;
begin
  if new.zone_id is null or new.device_id is null or new.mq135_value is null then
    return new;
  end if;

  select good_max, moderate_max, hazardous_max
  into configured_good_max, configured_moderate_max, configured_hazardous_max
  from public.aqm_threshold_settings
  where setting_id = true;

  if configured_good_max is null or configured_moderate_max is null or configured_hazardous_max is null then
    raise exception 'Air-quality thresholds are not configured.';
  end if;

  perform pg_advisory_xact_lock(
    hashtextextended(
      new.zone_id::text || ':' || new.device_id::text || ':'
        || coalesce(to_jsonb(new)->>'sensor_id', 'legacy'),
      0
    )
  );

  if new.mq135_value <= configured_good_max then
    update public.alerts a
    set status = 'Resolved'
    from public.air_quality_readings r
    where a.reading_id = r.reading_id
      and coalesce(to_jsonb(r)->>'sensor_id', '') = coalesce(to_jsonb(new)->>'sensor_id', '')
      and a.device_id = new.device_id
      and a.zone_id = new.zone_id
      and lower(coalesce(a.status, 'active')) in ('active', 'acknowledged', 'open');
    return new;
  end if;

  if new.mq135_value <= configured_moderate_max then
    alert_severity := 'Moderate';
  elsif new.mq135_value <= configured_hazardous_max then
    alert_severity := 'Hazardous';
  else
    alert_severity := 'Very Hazardous';
  end if;

  select max(r.mq135_value)
  into previous_alert_peak
  from public.alerts a
  join public.air_quality_readings r on r.reading_id = a.reading_id
  where a.device_id = new.device_id
    and a.zone_id = new.zone_id
    and a.alert_type = 'Air Quality'
    and a.severity = alert_severity
    and coalesce(to_jsonb(r)->>'sensor_id', '') = coalesce(to_jsonb(new)->>'sensor_id', '')
    and lower(coalesce(a.status, 'active')) not in ('resolved', 'closed');

  if previous_alert_peak is null or new.mq135_value > previous_alert_peak then
    insert into public.alerts (
      zone_id, device_id, reading_id, alert_type, severity, message, status, created_at
    )
    values (
      new.zone_id,
      new.device_id,
      new.reading_id,
      'Air Quality',
      alert_severity,
      'MQ-135 sensor ' || coalesce(to_jsonb(new)->>'sensor_id', 'unknown') || ' reading ' || new.mq135_value::text || ' is in the '
        || lower(alert_severity) || ' range.',
      'Active',
      coalesce(new.recorded_at, now())
    );
  end if;

  return new;
end;
$$;

revoke all on function public.aqm_track_hazardous_reading() from public, anon, authenticated;
notify pgrst, 'reload schema';
commit;
