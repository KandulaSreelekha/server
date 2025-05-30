/**
 * SPDX-FileCopyrightText: 2022 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { Configuration } from 'webpack'
import { defineConfig } from 'cypress'
import { join } from 'path'
import { removeDirectory } from 'cypress-delete-downloads-folder'

import cypressSplit from 'cypress-split'
import webpackPreprocessor from '@cypress/webpack-preprocessor'

import {
	applyChangesToNextcloud,
	configureNextcloud,
	startNextcloud,
	stopNextcloud,
	waitOnNextcloud,
} from './cypress/dockerNode'
import webpackConfig from './webpack.config.js'

export default defineConfig({
	projectId: '37xpdh',

	// 16/9 screen ratio
	viewportWidth: 1280,
	viewportHeight: 720,

	// Tries again 2 more times on failure
	retries: {
		runMode: 2,
		// do not retry in `cypress open`
		openMode: 0,
	},

	// Needed to trigger `after:run` events with cypress open
	experimentalInteractiveRunEvents: true,

	// disabled if running in CI but enabled in debug mode
	video: !process.env.CI || !!process.env.RUNNER_DEBUG,

	// faster video processing
	videoCompression: false,

	// Prevent elements to be scrolled under a top bar during actions (click, clear, type, etc). Default is 'top'.
	// https://github.com/cypress-io/cypress/issues/871
	scrollBehavior: 'center',

	// Visual regression testing
	env: {
		failSilently: false,
		type: 'actual',
	},

	screenshotsFolder: 'cypress/snapshots/actual',
	trashAssetsBeforeRuns: true,

	e2e: {
		// Disable session isolation
		testIsolation: false,

		requestTimeout: 30000,

		// We've imported your old cypress plugins here.
		// You may want to clean this up later by importing these.
		async setupNodeEvents(on, config) {
			on('file:preprocessor', webpackPreprocessor({ webpackOptions: webpackConfig as Configuration }))

			on('task', { removeDirectory })

			// This allows to store global data (e.g. the name of a snapshot)
			// because Cypress.env() and other options are local to the current spec file.
			const data = {}
			on('task', {
				setVariable({ key, value }) {
					data[key] = value
					return null
				},
				getVariable({ key }) {
					return data[key] ?? null
				},
			})

			// Disable spell checking to prevent rendering differences
			on('before:browser:launch', (browser, launchOptions) => {
				if (browser.family === 'chromium' && browser.name !== 'electron') {
					launchOptions.preferences.default['browser.enable_spellchecking'] = false
					return launchOptions
				}

				if (browser.family === 'firefox') {
					launchOptions.preferences['layout.spellcheckDefault'] = 0
					return launchOptions
				}

				if (browser.name === 'electron') {
					launchOptions.preferences.spellcheck = false
					return launchOptions
				}
			})

			// Remove container after run
			on('after:run', () => {
				if (!process.env.CI) {
					stopNextcloud()
				}
			})

			// Check if we are running the setup checks
			if (process.env.SETUP_TESTING === 'true') {
				console.log('Adding setup tests to specPattern 🧮')
				config.specPattern = [join(__dirname, 'cypress/e2e/core/setup.ts')]
				console.log('└─ Done')
			} else {
				// If we are not running the setup tests, we need to remove the setup tests from the specPattern
				cypressSplit(on, config)
			}

			// Before the browser launches
			// starting Nextcloud testing container
			const ip = await startNextcloud(process.env.BRANCH)

			// Setting container's IP as base Url
			config.baseUrl = `http://${ip}/index.php`
			await waitOnNextcloud(ip)
			await configureNextcloud()

			if (!process.env.CI) {
				await applyChangesToNextcloud()
			}

			// IMPORTANT: return the config otherwise cypress-split will not work
			return config
		},
	},

	component: {
		specPattern: ['core/**/*.cy.ts', 'apps/**/*.cy.ts'],
		devServer: {
			framework: 'vue',
			bundler: 'webpack',
			webpackConfig: async () => {
				process.env.npm_package_name = 'NcCypress'
				process.env.npm_package_version = '1.0.0'
				process.env.NODE_ENV = 'development'

				/**
				 * Needed for cypress stubbing
				 *
				 * @see https://github.com/sinonjs/sinon/issues/1121
				 * @see https://github.com/cypress-io/cypress/issues/18662
				 */
				const babel = require('./babel.config.js')
				babel.plugins.push([
					'@babel/plugin-transform-modules-commonjs',
					{
						loose: true,
					},
				])

				const config = webpackConfig
				config.module.rules.push({
					test: /\.svg$/,
					type: 'asset/source',
				})

				return config
			},
		},
	},
})
