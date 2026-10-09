-- Run after supabase-admin-core.sql and supabase-esp32-ingest.sql.
-- Uses the existing public.alerts table; it does not create a parallel alert table.
begin;

alter table public.alerts enable row level security;
revoke all on public.alerts from anon;
revoke insert, update, delete on public.alerts from authenticated;
grant select on public.alerts to authenticated;
grant update (status) on public.alerts to authenticated;

drop policy if exists aqm_staff_read_alerts on public.alerts;
create policy aqm_staff_read_alerts on public.alerts
  for select to authenticated
  using (public.aqm_is_active_staff());

drop policy if exists aqm_staff_update_alert_status on public.alerts;
create policy aqm_staff_update_alert_status on public.alerts
  for update to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());
drop policy if exists aqm_staff_restrict_alerts on public.alerts;
create policy aqm_staff_restrict_alerts on public.alerts as restrictive
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

create or replace function public.aqm_track_hazardous_reading()
returns trigger
language plpgsql
security definer
set search_path = ''
as $$
declare
  alert_severity text;
  previous_alert_peak numeric;
begin
  if new.zone_id is null or new.device_id is null or new.sensor_id is null or new.mq135_value is null then
    return new;
  end if;

  perform pg_advisory_xact_lock(
    hashtextextended(new.zone_id::text || ':' || new.device_id::text || ':' || new.sensor_id::text, 0)
  );

  if new.mq135_value <= 300 then
    -- GOOD ends the elevated episode, so the next rise can start new alerts.
    update public.alerts a
    set status = 'Resolved'
    from public.air_quality_readings r
    where a.reading_id = r.reading_id
      and r.sensor_id = new.sensor_id
      and a.device_id = new.device_id
      and a.zone_id = new.zone_id
      and lower(coalesce(a.status, 'active')) in ('active', 'acknowledged', 'open');
    return new;
  end if;

  if new.mq135_value <= 350 then
    alert_severity := 'Moderate';
  else
    alert_severity := 'Hazardous';
  end if;

  select max(r.mq135_value)
  into previous_alert_peak
  from public.alerts a
  join public.air_quality_readings r on r.reading_id = a.reading_id
  where a.device_id = new.device_id
    and a.zone_id = new.zone_id
    and a.alert_type = 'Air Quality'
    and a.severity = alert_severity
    and r.sensor_id = new.sensor_id
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
      'MQ-135 sensor ' || new.sensor_id::text || ' reading ' || new.mq135_value::text || ' is in the '
        || lower(alert_severity) || ' range.',
      'Active',
      coalesce(new.recorded_at, now())
    );
  end if;

  return new;
end;
$$;

revoke all on function public.aqm_track_hazardous_reading() from public, anon, authenticated;
drop trigger if exists aqm_track_hazardous_reading on public.air_quality_readings;
create trigger aqm_track_hazardous_reading
  after insert on public.air_quality_readings
  for each row execute function public.aqm_track_hazardous_reading();

update public.alerts a
set severity = case when r.mq135_value <= 350 then 'Moderate' else 'Hazardous' end,
    message = 'MQ-135 sensor ' || coalesce(r.sensor_id::text, 'unknown') || ' reading '
      || r.mq135_value::text || ' is in the '
      || case when r.mq135_value <= 350 then 'moderate' else 'hazardous' end || ' range.'
from public.air_quality_readings r
where a.reading_id = r.reading_id
  and a.alert_type = 'Air Quality'
  and r.mq135_value > 300;

with readings_with_episode as (
  select r.*,
         count(*) filter (where r.mq135_value <= 300) over (
           partition by r.device_id, r.zone_id, r.sensor_id
           order by coalesce(r.recorded_at, '-infinity'::timestamptz), r.reading_id
           rows between unbounded preceding and current row
         ) as episode_id
  from public.air_quality_readings r
),
elevated_readings as (
  select r.*,
         case when r.mq135_value <= 350 then 'Moderate' else 'Hazardous' end as alert_severity
  from readings_with_episode r
  where r.mq135_value > 300
    and r.zone_id is not null
    and r.device_id is not null
),
readings_with_alert_peak as (
  select r.*,
         max(r.mq135_value) over (
           partition by r.device_id, r.zone_id, r.sensor_id, r.episode_id, r.alert_severity
           order by coalesce(r.recorded_at, '-infinity'::timestamptz), r.reading_id
           rows between unbounded preceding and 1 preceding
         ) as previous_alert_peak
  from elevated_readings r
)
insert into public.alerts (
  zone_id, device_id, reading_id, alert_type, severity, message, status, created_at
)
select h.zone_id,
       h.device_id,
       h.reading_id,
       'Air Quality',
       h.alert_severity,
       'MQ-135 sensor ' || coalesce(h.sensor_id::text, 'unknown') || ' reading '
         || h.mq135_value::text || ' is in the '
         || lower(h.alert_severity) || ' range.',
       case when cleared.reading_id is null then 'Active' else 'Resolved' end,
       coalesce(h.recorded_at, now())
from readings_with_alert_peak h
left join lateral (
  select r.reading_id
  from public.air_quality_readings r
  where r.device_id = h.device_id
    and r.zone_id = h.zone_id
    and r.sensor_id is not distinct from h.sensor_id
    and r.mq135_value <= 300
    and (coalesce(r.recorded_at, '-infinity'::timestamptz), r.reading_id)
      > (coalesce(h.recorded_at, '-infinity'::timestamptz), h.reading_id)
  order by r.recorded_at asc nulls last, r.reading_id asc
  limit 1
) cleared on true
where (h.previous_alert_peak is null or h.mq135_value > h.previous_alert_peak)
  and not exists (
    select 1
    from public.alerts existing
    where existing.reading_id = h.reading_id
  );

notify pgrst, 'reload schema';
commit;
