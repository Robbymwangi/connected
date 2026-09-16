export type Student = {
  id: string
  classId: string
  name: string
  gender: 'F' | 'M'
  dob: string
}

/* Grade 4W roster: 28 students, matching the class's enrolment. The first eight
   have hand-written marks in fixtures/marks.ts; the rest get generated ones. */
export const students: Student[] = [
  { id: 's1', classId: 'class-4w', name: 'Wanjiku Njoroge', gender: 'F', dob: '2016-03-12' },
  { id: 's2', classId: 'class-4w', name: 'Kofi Mensah', gender: 'M', dob: '2016-07-04' },
  { id: 's3', classId: 'class-4w', name: 'Amina Osei', gender: 'F', dob: '2016-01-19' },
  { id: 's4', classId: 'class-4w', name: 'Liam Osei', gender: 'M', dob: '2015-08-28' },
  { id: 's5', classId: 'class-4w', name: 'Fatou Diallo', gender: 'F', dob: '2015-11-03' },
  { id: 's6', classId: 'class-4w', name: 'Amara Kamau', gender: 'M', dob: '2016-02-15' },
  { id: 's7', classId: 'class-4w', name: 'Nia Mensah', gender: 'F', dob: '2016-06-22' },
  { id: 's8', classId: 'class-4w', name: 'Seun Adeyemi', gender: 'M', dob: '2016-04-09' },
  { id: 's9', classId: 'class-4w', name: 'Brian Otieno', gender: 'M', dob: '2016-05-30' },
  { id: 's10', classId: 'class-4w', name: 'Faith Wambui', gender: 'F', dob: '2016-09-14' },
  { id: 's11', classId: 'class-4w', name: 'Kevin Mutua', gender: 'M', dob: '2015-12-01' },
  { id: 's12', classId: 'class-4w', name: 'Mercy Achieng', gender: 'F', dob: '2016-02-27' },
  { id: 's13', classId: 'class-4w', name: 'Dennis Kiprotich', gender: 'M', dob: '2016-08-08' },
  { id: 's14', classId: 'class-4w', name: 'Joy Nyambura', gender: 'F', dob: '2016-03-03' },
  { id: 's15', classId: 'class-4w', name: 'Samuel Ochieng', gender: 'M', dob: '2015-10-17' },
  { id: 's16', classId: 'class-4w', name: 'Grace Wairimu', gender: 'F', dob: '2016-07-21' },
  { id: 's17', classId: 'class-4w', name: 'Peter Kamau', gender: 'M', dob: '2016-01-05' },
  { id: 's18', classId: 'class-4w', name: 'Esther Chebet', gender: 'F', dob: '2016-04-26' },
  { id: 's19', classId: 'class-4w', name: 'Victor Mwangi', gender: 'M', dob: '2015-09-12' },
  { id: 's20', classId: 'class-4w', name: 'Lucy Akinyi', gender: 'F', dob: '2016-06-06' },
  { id: 's21', classId: 'class-4w', name: 'Ian Kipchoge', gender: 'M', dob: '2016-10-19' },
  { id: 's22', classId: 'class-4w', name: 'Purity Njeri', gender: 'F', dob: '2016-02-02' },
  { id: 's23', classId: 'class-4w', name: 'Collins Omondi', gender: 'M', dob: '2015-11-24' },
  { id: 's24', classId: 'class-4w', name: 'Naomi Wanjiru', gender: 'F', dob: '2016-05-11' },
  { id: 's25', classId: 'class-4w', name: 'Elijah Kimani', gender: 'M', dob: '2016-08-30' },
  { id: 's26', classId: 'class-4w', name: 'Diana Auma', gender: 'F', dob: '2016-03-18' },
  { id: 's27', classId: 'class-4w', name: 'Felix Rotich', gender: 'M', dob: '2015-12-13' },
  { id: 's28', classId: 'class-4w', name: 'Hannah Moraa', gender: 'F', dob: '2016-07-07' },
]

export function rosterFor(classId: string): Student[] {
  return students.filter((s) => s.classId === classId)
}

export function initials(name: string): string {
  return name
    .split(' ')
    .map((w) => w[0] ?? '')
    .join('')
    .slice(0, 2)
    .toUpperCase()
}
