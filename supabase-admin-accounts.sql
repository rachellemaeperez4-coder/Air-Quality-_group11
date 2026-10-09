-- Run after supabase-admin-core.sql for Staff role and account-status controls.
begin;

create or replace function public.aqm_staff_update_user(
  p_user_id integer,
  p_role text,
  p_account_status text
) returns void
language plpgsql
security definer
set search_path = ''
as $$
declare
  target_auth_id uuid;
  active_staff_count integer;
begin
  if not public.aqm_is_active_staff() then
    raise exception 'Active staff access is required.' using errcode = '42501';
  end if;
  if p_role is null or p_role not in ('User', 'Staff')
     or p_account_status is null or p_account_status not in ('Active', 'Disabled') then
    raise exception 'Invalid role or account status.' using errcode = '22023';
  end if;
  select auth_user_id into target_auth_id
  from public.users where user_id = p_user_id;
  if target_auth_id is null then
    raise exception 'The selected account does not exist or is not linked to Auth.' using errcode = 'P0002';
  end if;
  if target_auth_id = (select auth.uid())
    and (p_role <> 'Staff' or p_account_status <> 'Active') then
    raise exception 'You cannot disable or demote your own staff account.' using errcode = '42501';
  end if;
  if p_role <> 'Staff' or p_account_status <> 'Active' then
    select count(*) into active_staff_count
    from public.users
    where lower(role) = 'staff' and account_status = 'Active'
      and user_id <> p_user_id;
    if active_staff_count = 0 then
      raise exception 'At least one active staff account must remain.' using errcode = '23514';
    end if;
  end if;
  update public.users
  set role = p_role, account_status = p_account_status
  where user_id = p_user_id;
end;
$$;
revoke all on function public.aqm_staff_update_user(integer, text, text) from public, anon;
grant execute on function public.aqm_staff_update_user(integer, text, text) to authenticated;

notify pgrst, 'reload schema';
commit;
