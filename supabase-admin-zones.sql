-- Run after supabase-admin-core.sql to enable Staff-only zone management.
begin;
alter table public.zones enable row level security;
grant insert, update, delete on public.zones to authenticated;
drop policy if exists aqm_staff_manage_zones on public.zones;
create policy aqm_staff_manage_zones on public.zones
  for all to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

-- Replace the older Staff-only restrictive guards with the current active-Staff
-- check so they cannot block the management policy above.
drop policy if exists aqm_monitor_insert_guard on public.zones;
create policy aqm_monitor_insert_guard on public.zones as restrictive
  for insert to authenticated
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_update_guard on public.zones;
create policy aqm_monitor_update_guard on public.zones as restrictive
  for update to authenticated
  using (public.aqm_is_active_staff())
  with check (public.aqm_is_active_staff());

drop policy if exists aqm_monitor_delete_guard on public.zones;
create policy aqm_monitor_delete_guard on public.zones as restrictive
  for delete to authenticated
  using (public.aqm_is_active_staff());

do $$
declare sequence_name text;
begin
  sequence_name := pg_get_serial_sequence('public.zones', 'zone_id');
  if sequence_name is not null then
    execute format('grant usage, select on sequence %s to authenticated', sequence_name);
  end if;
end;
$$;
commit;
