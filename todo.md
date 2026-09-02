Review and ensure everything is optimize and using the best practice and no security issue
use react activity to maintain page state when navigate and return back to a route like nextjs. For example if i add a value inside a form and navigate and return the value is still there. no remounting
use offline like nextjs
form prefetching
optimistic update
swr config

Can we generate a full static site if all pages are static?
Update the docs instruction
Document ppr cache at edge
why do we need to use btoa to upload file?

Why page import have LaravelRsc\PageRoute;, can't these be used outside of laravel?
docs is now stale

support view transition when stable in react

support api endpoints

test for route generation

document bun and node usage if differ
there is alot of security vulnerabilty with nextjs and react server functions especially. do we have these issues as well?


rsc-router-laravel-adapter or rsc-laravel-adapter

I know nextjs recently release instant navigation, no matter how slow is the network navigation happens instantly but am wondering if it to address a issue they have which we don't have, our stream kick off instantly once hit the server but i don't know if it work on slow network altho we have prefetch. So next js limitation now is with instant navigation it don't fetch the data until it navigate which I kind of don't like but I like as you click it navigate instantly but I will be guided by you on this

With nextjs revalidate refresh the entire page altho look seemless, can we get targetted revalidation. Example if I have two tables on a page and I only affect one table with an action, could we only refresh data for one table instead of the entire page? but someone user might also want to refresh everything