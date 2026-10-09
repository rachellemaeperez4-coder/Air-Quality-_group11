-- Run in Supabase SQL Editor after supabase-esp32-ingest.sql.
-- Set target_device_id to the device_id assigned to your ESP32 in public.devices.
-- This rotates that device's token. Copy the one-time result into DEVICE_TOKEN
-- in the ESP32 sketch; never save the raw token in this SQL file.
with generated_token as materialized (
  -- Device ID 6 is the ESP32 currently registered in Zone ID 2.
  select 6::integer as device_id,
         encode(extensions.gen_random_bytes(32), 'hex') as raw_token
),
stored_token as (
  insert into public.aqm_device_tokens (device_id, token_hash, active, created_at)
  select device_id,
         extensions.crypt(raw_token, extensions.gen_salt('bf')),
         true,
         now()
  from generated_token
  on conflict (device_id) do update
    set token_hash = excluded.token_hash,
        active = true,
        created_at = now()
  returning device_id
)
select generated_token.device_id, generated_token.raw_token
from generated_token
join stored_token using (device_id);
