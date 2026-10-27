import { createAppConfig } from '@nextcloud/vite-config'

const appConfig = createAppConfig({ main: 'src/main.js' }, { inlineCSS: false, minify: true })

export default async (environment) => {
  const config = await appConfig(environment)
  config.build.rollupOptions.output.entryFileNames = (chunk) => `js/serviceplanner-${chunk.name}.js`
  return config
}
