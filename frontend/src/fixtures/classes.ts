/* Classes (streams) in the school. Shared by Assessments and Classes. */
export type SchoolClass = {
  id: string
  grade: string
  stream: string
  teacher: string
  enrolment: number
  subjects: string[]
  assessments: number
}

export const classes: SchoolClass[] = [
  { id: 'class-4w', grade: 'Grade 4', stream: '4W', teacher: 'Mr. John Doe', enrolment: 28, subjects: ['English', 'Maths', 'Science'], assessments: 6 },
  { id: 'class-4e', grade: 'Grade 4', stream: '4E', teacher: 'Ms. Akinyi', enrolment: 26, subjects: ['English', 'Maths'], assessments: 4 },
  { id: 'class-5a', grade: 'Grade 5', stream: '5A', teacher: 'Mr. John Doe', enrolment: 30, subjects: ['English', 'Maths', 'Science'], assessments: 7 },
  { id: 'class-5b', grade: 'Grade 5', stream: '5B', teacher: 'Ms. Osei', enrolment: 29, subjects: ['English', 'Maths'], assessments: 5 },
  { id: 'class-6a', grade: 'Grade 6', stream: '6A', teacher: 'Ms. Akinyi', enrolment: 25, subjects: ['English', 'Maths', 'Science'], assessments: 3 },
]

export const grades = ['Grade 4', 'Grade 5', 'Grade 6']
