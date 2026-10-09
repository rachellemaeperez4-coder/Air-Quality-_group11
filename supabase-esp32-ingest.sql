-- ESP32 ingestion setup. Run this in the Supabase SQL Editor as project owner.
-- Prerequisite: public.zones, public.devices, public.sensors and public.air_quality_readings already exist.
-- This keeps direct table inserts blocked; the ESP32 may only call aqm_ingest_reading.

begin;

create extension if not exists pgcrypto with schema extensions;

create table if not exists public.aqm_device_tokens (
  device_id integer primary key references public.devices(device_id) on delete cascade,
  token_hash text not null,
  active boolean not null default true,
  created_at timestamptz not null default now()
);

alter table public.aqm_device_tokens enable row level security;
revoke all on public.aqm_device_tokens from anon, authenticated;

alter table public.air_quality_readings
  add column if not exists sensor_id integer references public.sensors(sensor_id),
  drop column if exists co_value,
  drop column if exists temperature,
  drop column if exists humidity;

drop function if exists public.aqm_ingest_reading(integer, integer, numeric, text);
drop function if exists public.aqm_ingest_reading(integer, integer, numeric, numeric, numeric, numeric, text);
drop function if exists public.aqm_ingest_reading(integer, integer, integer, numeric, numeric, numeric, numeric, text);
drop function if exists public.aqm_ingest_reading(integer, integer, integer, numeric, text);

create function public.aqm_ingest_reading(
  p_zone_id integer,
  p_device_id integer,
  p_sensor_id integer,
  p_mq135_value numeric,
  p_air_quality_status text
) returns void
language plpgsql
security definer
set search_path = public, extensions
as $$
declare
  supplied_token text := coalesce(
    current_setting('request.headers', true)::json ->> 'x-device-token', ''
  );
begin
  if supplied_token = '' or not exists (
    select 1
    from public.aqm_device_tokens t
    where t.device_id = p_device_id
      and t.active
      and t.token_hash = extensions.crypt(supplied_token, t.token_hash)
  ) then
    raise exception 'Invalid device token' using errcode = '28000';
  end if;

  if not exists (
    select 1
    from public.devices d
    where d.device_id = p_device_id
      and d.zone_id = p_zone_id
  ) then
    raise exception 'Device ID % is not registered in Zone ID %. Check ZONE_ID and DEVICE_ID in the ESP32 sketch.',
      p_device_id, p_zone_id using errcode = '23503';
  end if;

  if not exists (
    select 1
    from public.sensors s
    where s.sensor_id = p_sensor_id
      and s.device_id = p_device_id
  ) then
    raise exception 'Sensor ID % is not registered to Device ID %. Check SENSOR_1_ID and SENSOR_2_ID in the ESP32 sketch.',
      p_sensor_id, p_device_id using errcode = '23503';
  end if;

  if p_mq135_value is null or p_mq135_value < 0 then
    raise exception 'Invalid MQ135 value' using errcode = '22023';
  end if;

  insert into public.air_quality_readings
    (zone_id, device_id, sensor_id, mq135_value, air_quality_status, recorded_at)
  values
    (p_zone_id, p_device_id, p_sensor_id, p_mq135_value, nullif(btrim(p_air_quality_status), ''), now());
end;
$$;

revoke all on function public.aqm_ingest_reading(integer, integer, integer, numeric, text) from public;
grant execute on function public.aqm_ingest_reading(integer, integer, integer, numeric, text) to anon;

notify pgrst, 'reload schema';
commit;

-- After this setup, set the device_id in supabase-esp32-device-token.sql to
-- the existing ESP32's device_id, run that file once, and copy its
-- one-time raw token directly into DEVICE_TOKEN in the ESP32 sketch.
-- The ingestion function verifies that the supplied zone, device, and sensor
-- IDs are linked correctly when the ESP32 uploads a reading.
-- select zone_id, zone_name from public.zones order by zone_id;
-- select device_id, device_name, zone_id from public.devices order by device_id;
