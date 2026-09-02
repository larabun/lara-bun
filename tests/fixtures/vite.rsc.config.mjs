// Vite config for the fixture app the JS suite builds.
//
// The build runs a project's own config and generates nothing, so the tests
// supply one the same way a real project does.
import { rscRoutes } from '../../resources/vite.ts'

export default {
  plugins: [rscRoutes()],
}
