import './app.css'
import type { ReactNode } from 'react'
import { Nav } from '../Nav'

export const metadata = { title: { template: '%s · LaraBun', default: 'LaraBun Docs' }, description: 'default description' }

export default function Layout({ children }: { children: ReactNode }) {
  return (
    <html lang="en">
      <head>
        <meta charSet="utf-8" />
      </head>
      <body>
        <Nav />
        {children}
      </body>
    </html>
  )
}
