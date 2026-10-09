-- Run after supabase-admin-hazardous-threshold.sql.
-- Exposes only the latest MQ-135 reading and zone label for up to three zones.
begin;

create or replace function public.aqm_public_latest_zone_readings()
returns table (
  zone_name text,
  location text,
  mq135_value numeric,
  quality_status text,
  recorded_at timestamptz
)
language plpgsql
stable
security definer
set search_path = ''
as $$
declare
  configured_good_max integer;
  configured_moderate_max integer;
  configured_hazardous_max integer;
begin
  select settings.good_max, settings.moderate_max, settings.hazardous_max
  into configured_good_max, configured_moderate_max, configured_hazardous_max
  from public.aqm_threshold_settings settings
  where settings.setting_id = true;

  if configured_good_max is null
    or configured_moderate_max is null
    or configured_hazardous_max is null then
    raise exception 'Air-quality thresholds are not configured.';
  end if;

  return query
  with latest_per_zone as (
    select distinct on (r.zone_id)
      z.zone_name::text as zone_name,
      nullif(btrim(z.location::text), '') as location,
      r.mq135_value::numeric as mq135_value,
      r.recorded_at::timestamptz as recorded_at
    from public.air_quality_readings r
    join public.zones z on z.zone_id = r.zone_id
    where r.zone_id is not null
      and r.mq135_value is not null
    order by r.zone_id, r.recorded_at desc nulls last, r.reading_id desc
  )
  select
    latest.zone_name,
    latest.location,
    latest.mq135_value,
    case
      when latest.mq135_value < 0 then 'Unknown'
      when latest.mq135_value <= configured_good_max then 'Good'
      when latest.mq135_value <= configured_moderate_max then 'Moderate'
      when latest.mq135_value <= configured_hazardous_max then 'Hazardous'
      else 'Very Hazardous'
    end::text,
    latest.recorded_at
  from latest_per_zone latest
  order by latest.recorded_at desc nulls last, latest.zone_name
  limit 3;
end;
$$;

revoke all on function public.aqm_public_latest_zone_readings() from public;
grant execute on function public.aqm_public_latest_zone_readings() to anon, authenticated;

notify pgrst, 'reload schema';
commit;
