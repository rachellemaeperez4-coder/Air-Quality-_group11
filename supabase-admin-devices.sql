-- Run after supabase-admin-core.sql to enable Staff-only device management.
begin;
alter table public.devices enable row level security;
grant insert, update, delete on public.devices to authenticated;
drop policy if exists aqm_staff_manage_devices on public.devices;
create policy aqm_staff_manage_devices on public.devices
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

-- Replace the older Staff-only restrictive guards with the current active-Staff
-- check so they cannot block the management policy above.
drop policy if exists aqm_monitor_insert_guard on public.devices;
create policy aqm_monitor_insert_guard on public.devices as restrictive
  for insert to authenticated
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_update_guard on public.devices;
create policy aqm_monitor_update_guard on public.devices as restrictive
  for update to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_delete_guard on public.devices;
create policy aqm_monitor_delete_guard on public.devices as restrictive
  for delete to authenticated
  using (public.aqm_is_active_staff());

do $$
declare sequence_name text;
begin
  sequence_name := pg_get_serial_sequence('public.devices', 'device_id');
  if sequence_name is not null then
    execute format('grant usage, select on sequence %s to authenticated', sequence_name);
  end if;
end;
$$;
commit;
