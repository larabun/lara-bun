Review and ensure everything is optimize and using the best practice and no security issue
use offline like nextjs 
document all features including new features such as withoutjs

Can we generate a full static site if all pages are static?
Update the docs instruction
Document ppr cache at edge

Why page import have LaravelRsc\PageRoute;, can't these be used outside of laravel?
docs is now stale

support view transition when stable in react

support api endpoints

test for route generation

document bun and node usage if differ
there is alot of security vulnerabilty with nextjs and react server functions especially. do we have these issues as well?


rsc-router-laravel-adapter or rsc-laravel-adapter
vite-plugin-rsc-router

With nextjs revalidate refresh the entire page altho look seemless, can we get targetted revalidation. Example if I have two tables on a page and I only affect one table with an action, could we only refresh data for one table instead of the entire page? but someone user might also want to refresh everything


Still open from the roadmap: targeted revalidation, withoutClientJs, and static export. Export is now mostly a matter of walking the same results and refusing anything that isn't static — PPR shells can't be exported, since nothing fills them on a static host.
switch to navigation api when become stable

add skills/something like laravel boost
add mcp

announce targetted rerender etc

Does our client library tree shake etc?

do a final code clean up and optimization to reduce size and increase performance without breaking

can bun do static serving
measure performance

standard to follow web standards

laravel have route generation helper/autocomplete does the js instances have this? 

Can server function do multiple submit at once, I know nextjs sequence them because it kind of difficult to handle multiple but that is next striction and not server funciton.

Also does our form support standard schema validation, I know it was built most for laravel but since we extend scope it should support it