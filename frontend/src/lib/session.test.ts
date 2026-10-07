import { describe, expect, it } from 'vitest'
import { toCurrentUser } from './session'

describe('toCurrentUser', () => {
  it('splits the first name and initials off the full name', () => {
    const user = toCurrentUser({
      id: 'u-1',
      name: 'Mercy Achieng',
      email: 'm.achieng@greenvalley.test',
      is_admin: false,
      moderated_subject_ids: [],
    })
    expect(user.firstName).toBe('Mercy')
    expect(user.fullName).toBe('Mercy Achieng')
    expect(user.initials).toBe('MA')
  })

  it('falls back to the whole name when there is only one word', () => {
    const user = toCurrentUser({
      id: 'u-2',
      name: 'Admin',
      email: 'admin@greenvalley.test',
      is_admin: true,
      moderated_subject_ids: [],
    })
    expect(user.firstName).toBe('Admin')
    expect(user.initials).toBe('A')
  })

  it('shows Administrator only for an admin account, Teacher otherwise', () => {
    const admin = toCurrentUser({ id: '1', name: 'A', email: 'a@x.test', is_admin: true, moderated_subject_ids: [] })
    const teacher = toCurrentUser({ id: '2', name: 'B', email: 'b@x.test', is_admin: false, moderated_subject_ids: [] })
    expect(admin.role).toBe('Administrator')
    expect(teacher.role).toBe('Teacher')
  })

  it('keeps the subjects an account moderates, one by one, never flattened to a yes or no', () => {
    const some = toCurrentUser({
      id: '2',
      name: 'B',
      email: 'b@x.test',
      is_admin: false,
      moderated_subject_ids: ['subj-1', 'subj-2'],
    })
    expect(some.moderatedSubjectIds).toEqual(['subj-1', 'subj-2'])
  })
})
