-- Run after supabase-admin-core.sql to enable Staff-only sensor management.
begin;
alter table public.sensors enable row level security;
grant insert, update, delete on public.sensors to authenticated;
drop policy if exists aqm_staff_manage_sensors on public.sensors;
create policy aqm_staff_manage_sensors on public.sensors
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

-- Replace older Staff-only restrictive guards so they use the same active-Staff
-- check as the management policy above.
drop policy if exists aqm_monitor_insert_guard on public.sensors;
create policy aqm_monitor_insert_guard on public.sensors as restrictive
  for insert to authenticated
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_update_guard on public.sensors;
create policy aqm_monitor_update_guard on public.sensors as restrictive
  for update to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_delete_guard on public.sensors;
create policy aqm_monitor_delete_guard on public.sensors as restrictive
  for delete to authenticated
  using (public.aqm_is_active_staff());

do $$
declare column_name text; sequence_name text;
begin
  foreach column_name in array array['sensor_id', 'id'] loop
    sequence_name := pg_get_serial_sequence('public.sensors', column_name);
    if sequence_name is not null then
      execute format('grant usage, select on sequence %s to authenticated', sequence_name);
    end if;
  end loop;
end;
$$;
commit;
