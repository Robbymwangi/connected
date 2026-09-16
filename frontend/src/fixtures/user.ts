/* Static stand-in for the signed-in user until auth exists. */
export const currentUser = {
  id: 'u-1',
  firstName: 'John',
  fullName: 'John Doe',
  initials: 'JD',
  role: 'Teacher',
  email: 'john.doe@school.edu',
  /* Subject heads and heads of department may resolve cross-teacher conflicts
     outright (ADR 0002). Placeholder until roles come with authentication. */
  canModerate: false,
}
