-- Run in Supabase SQL Editor. Registered User/Staff accounts may read
-- monitoring data. Staff may manage sensors and delete readings; Users remain
-- read-only.
begin;
do $$
declare t text;
begin
  foreach t in array array['air_quality_readings', 'zones', 'devices', 'sensors'] loop
    execute format('alter table public.%I enable row level security', t);
    execute format('grant select on public.%I to authenticated', t);
    execute format('drop policy if exists aqm_monitor_read on public.%I', t);
    execute format('create policy aqm_monitor_read on public.%I for select to authenticated using (exists (select 1 from public.users u where u.auth_user_id = (select auth.uid()) and lower(u.role) in (''user'', ''staff'')))', t);
    execute format('drop policy if exists aqm_monitor_insert_guard on public.%I', t);
    execute format('create policy aqm_monitor_insert_guard on public.%I as restrictive for insert to authenticated with check (exists (select 1 from public.users u where u.auth_user_id = (select auth.uid()) and lower(u.role) = ''staff''))', t);
    execute format('drop policy if exists aqm_monitor_update_guard on public.%I', t);
    execute format('create policy aqm_monitor_update_guard on public.%I as restrictive for update to authenticated using (exists (select 1 from public.users u where u.auth_user_id = (select auth.uid()) and lower(u.role) = ''staff'')) with check (exists (select 1 from public.users u where u.auth_user_id = (select auth.uid()) and lower(u.role) = ''staff''))', t);
    execute format('drop policy if exists aqm_monitor_delete_guard on public.%I', t);
    execute format('create policy aqm_monitor_delete_guard on public.%I as restrictive for delete to authenticated using (exists (select 1 from public.users u where u.auth_user_id = (select auth.uid()) and lower(u.role) = ''staff''))', t);
  end loop;

  for t in
    select pg_get_serial_sequence('public.sensors', a.attname)
    from pg_attribute a
    where a.attrelid = 'public.sensors'::regclass
      and a.attnum > 0
      and not a.attisdropped
      and pg_get_serial_sequence('public.sensors', a.attname) is not null
  loop
    execute format('grant usage, select on sequence %s to authenticated', t);
  end loop;
end;
$$;

grant delete on public.air_quality_readings to authenticated;
drop policy if exists aqm_staff_delete_readings on public.air_quality_readings;
create policy aqm_staff_delete_readings on public.air_quality_readings
  for delete to authenticated
  using (exists (
    select 1 from public.users u
    where u.auth_user_id = (select auth.uid()) and lower(u.role) = 'staff'
  ));

grant insert, update, delete on public.sensors to authenticated;
drop policy if exists aqm_staff_manage_sensors on public.sensors;
create policy aqm_staff_manage_sensors on public.sensors
  for all to authenticated
  using (exists (
    select 1 from public.users u
    where u.auth_user_id = (select auth.uid()) and lower(u.role) = 'staff'
  ))
  with check (exists (
    select 1 from public.users u
    where u.auth_user_id = (select auth.uid()) and lower(u.role) = 'staff'
  ));
commit;
