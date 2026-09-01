'use server'

export async function greet(name: string) {
  return { message: `Hi ${name} from a server action`, ranAt: 'server' }
}
