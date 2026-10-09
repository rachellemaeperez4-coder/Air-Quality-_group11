  -- Run after supabase-users-migration.sql and supabase-readings-access.sql.
  -- Shared account status and RLS helpers used by the optional Staff modules.
  begin;

  alter table public.users
    add column if not exists account_status text not null default 'Active';

  do $$
  begin
    if not exists (
      select 1 from pg_constraint
      where conrelid = 'public.users'::regclass
        and conname = 'users_account_status_check'
    ) then
      alter table public.users
        add constraint users_account_status_check
        check (account_status in ('Active', 'Disabled'));
    end if;
  end;
  $$;

  grant select (user_id, auth_user_id, name, email, role, created_at, account_status)
    on public.users to authenticated;

  create or replace function public.aqm_is_active_staff()
  returns boolean
  language sql
  stable
  security definer
  set search_path = ''
  as $$
    select exists (
      select 1 from public.users u
      where u.auth_user_id = (select auth.uid())
        and lower(u.role) = 'staff'
        and u.account_status = 'Active'
    );
  $$;

  create or replace function public.aqm_is_active_account()
  returns boolean
  language sql
  stable
  security definer
  set search_path = ''
  as $$
    select exists (
      select 1 from public.users u
      where u.auth_user_id = (select auth.uid())
        and u.account_status = 'Active'
        and lower(u.role) in ('user', 'staff')
    );
  $$;

  revoke all on function public.aqm_is_active_staff() from public, anon;
  revoke all on function public.aqm_is_active_account() from public, anon;
  grant execute on function public.aqm_is_active_staff() to authenticated;
  grant execute on function public.aqm_is_active_account() to authenticated;

  drop policy if exists aqm_users_staff_read on public.users;
  create policy aqm_users_staff_read on public.users for select to authenticated
    using (public.aqm_is_active_staff());
  drop policy if exists aqm_users_restrict_own on public.users;
  create policy aqm_users_restrict_own on public.users as restrictive for all to authenticated
    using (
      (select auth.uid()) = auth_user_id
      or public.aqm_is_active_staff()
    )
    with check (false);

  do $$
  declare table_name text;
  begin
    foreach table_name in array array['air_quality_readings', 'zones', 'devices', 'sensors'] loop
      execute format('alter table public.%I enable row level security', table_name);
      execute format('drop policy if exists aqm_active_monitor_read on public.%I', table_name);
      execute format(
        'create policy aqm_active_monitor_read on public.%I as restrictive for select to authenticated using (public.aqm_is_active_account())',
        table_name
      );
    end loop;
  end;
  $$;

  notify pgrst, 'reload schema';
  commit;
