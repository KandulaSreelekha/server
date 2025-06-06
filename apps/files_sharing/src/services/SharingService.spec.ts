/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import type { OCSResponse } from '@nextcloud/typings/ocs'

import { File, Folder } from '@nextcloud/files'
import { ShareType } from '@nextcloud/sharing'
import { beforeAll, beforeEach, describe, expect, test, vi } from 'vitest'

import { getContents } from './SharingService'
import * as auth from '@nextcloud/auth'
import logger from './logger'

const TAG_FAVORITE = '_$!<Favorite>!$_'

const axios = vi.hoisted(() => ({ get: vi.fn() }))
vi.mock('@nextcloud/auth')
vi.mock('@nextcloud/axios', () => ({ default: axios }))

// Mock TAG
beforeAll(() => {
	window.OC = {
		...window.OC,
		TAG_FAVORITE,
	}
})

describe('SharingService methods definitions', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		axios.get.mockImplementation(async (): Promise<unknown> => {
			return {
				data: {
					ocs: {
						meta: {
							status: 'ok',
							statuscode: 200,
							message: 'OK',
						},
						data: [],
					},
				} as OCSResponse,
			}
		})
	})

	test('Shared with you', async () => {
		await getContents(true, false, false, false, [])

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenNthCalledWith(1, 'http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/shares', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				shared_with_me: true,
				include_tags: true,
			},
		})
		expect(axios.get).toHaveBeenNthCalledWith(2, 'http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/remote_shares', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				include_tags: true,
			},
		})
	})

	test('Shared with others', async () => {
		await getContents(false, true, false, false, [])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/shares', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				shared_with_me: false,
				include_tags: true,
			},
		})
	})

	test('Pending shares', async () => {
		await getContents(false, false, true, false, [])

		expect(axios.get).toHaveBeenCalledTimes(2)
		expect(axios.get).toHaveBeenNthCalledWith(1, 'http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/shares/pending', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				include_tags: true,
			},
		})
		expect(axios.get).toHaveBeenNthCalledWith(2, 'http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/remote_shares/pending', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				include_tags: true,
			},
		})
	})

	test('Deleted shares', async () => {
		await getContents(false, true, false, false, [])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(axios.get).toHaveBeenCalledWith('http://nextcloud.local/ocs/v2.php/apps/files_sharing/api/v1/shares', {
			headers: {
				'Content-Type': 'application/json',
			},
			params: {
				shared_with_me: false,
				include_tags: true,
			},
		})
	})

	test('Unknown owner', async () => {
		vi.spyOn(auth, 'getCurrentUser').mockReturnValue(null)
		const results = await getContents(false, true, false, false, [])

		expect(results.folder.owner).toEqual(null)
	})
})

describe('SharingService filtering', () => {
	beforeEach(() => {
		vi.resetAllMocks()
		axios.get.mockImplementation(async (): Promise<unknown> => {
			return {
				data: {
					ocs: {
						meta: {
							status: 'ok',
							statuscode: 200,
							message: 'OK',
						},
						data: [
							{
								id: '62',
								share_type: ShareType.User,
								uid_owner: 'test',
								displayname_owner: 'test',
								permissions: 31,
								stime: 1688666292,
								expiration: '2023-07-13 00:00:00',
								token: null,
								path: '/Collaborators',
								item_type: 'folder',
								item_permissions: 31,
								mimetype: 'httpd/unix-directory',
								storage: 224,
								item_source: 419413,
								file_source: 419413,
								file_parent: 419336,
								file_target: '/Collaborators',
								item_size: 41434,
								item_mtime: 1688662980,
							},
						],
					},
				},
			}
		})
	})

	test('Shared with others filtering', async () => {
		const shares = await getContents(false, true, false, false, [ShareType.User])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(shares.contents).toHaveLength(1)
		expect(shares.contents[0].fileid).toBe(419413)
		expect(shares.contents[0]).toBeInstanceOf(Folder)
	})

	test('Shared with others filtering empty', async () => {
		const shares = await getContents(false, true, false, false, [ShareType.Link])

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(shares.contents).toHaveLength(0)
	})
})

describe('SharingService share to Node mapping', () => {
	const shareFile = {
		id: '66',
		share_type: 0,
		uid_owner: 'test',
		displayname_owner: 'test',
		permissions: 19,
		can_edit: true,
		can_delete: true,
		stime: 1688721609,
		parent: null,
		expiration: '2023-07-14 00:00:00',
		token: null,
		uid_file_owner: 'test',
		note: '',
		label: null,
		displayname_file_owner: 'test',
		path: '/document.md',
		item_type: 'file',
		item_permissions: 27,
		mimetype: 'text/markdown',
		has_preview: true,
		storage_id: 'home::test',
		storage: 224,
		item_source: 530936,
		file_source: 530936,
		file_parent: 419336,
		file_target: '/document.md',
		item_size: 123,
		item_mtime: 1688721600,
		share_with: 'user00',
		share_with_displayname: 'User00',
		share_with_displayname_unique: 'user00@domain.com',
		status: {
			status: 'away',
			message: null,
			icon: null,
			clearAt: null,
		},
		mail_send: 0,
		hide_download: 0,
		attributes: null,
		tags: [],
	}

	const shareFolder = {
		id: '67',
		share_type: 0,
		uid_owner: 'test',
		displayname_owner: 'test',
		permissions: 31,
		can_edit: true,
		can_delete: true,
		stime: 1688721629,
		parent: null,
		expiration: '2023-07-14 00:00:00',
		token: null,
		uid_file_owner: 'test',
		note: '',
		label: null,
		displayname_file_owner: 'test',
		path: '/Folder',
		item_type: 'folder',
		item_permissions: 31,
		mimetype: 'httpd/unix-directory',
		has_preview: false,
		storage_id: 'home::test',
		storage: 224,
		item_source: 531080,
		file_source: 531080,
		file_parent: 419336,
		file_target: '/Folder',
		item_size: 0,
		item_mtime: 1688721623,
		share_with: 'user00',
		share_with_displayname: 'User00',
		share_with_displayname_unique: 'user00@domain.com',
		status: {
			status: 'away',
			message: null,
			icon: null,
			clearAt: null,
		},
		mail_send: 0,
		hide_download: 0,
		attributes: null,
		tags: [TAG_FAVORITE],
	}

	const remoteFileAccepted = {
		mimetype: 'text/markdown',
		mtime: 1688721600,
		permissions: 19,
		type: 'file',
		file_id: 1234,
		id: 4,
		share_type: ShareType.User,
		parent: null,
		remote: 'http://exampe.com',
		remote_id: '12345',
		share_token: 'share-token',
		name: '/test.md',
		mountpoint: '/shares/test.md',
		owner: 'owner-uid',
		user: 'sharee-uid',
		accepted: true,
	}

	const remoteFilePending = {
		mimetype: 'text/markdown',
		mtime: 1688721600,
		permissions: 19,
		type: 'file',
		file_id: 1234,
		id: 4,
		share_type: ShareType.User,
		parent: null,
		remote: 'http://exampe.com',
		remote_id: '12345',
		share_token: 'share-token',
		name: '/test.md',
		mountpoint: '/shares/test.md',
		owner: 'owner-uid',
		user: 'sharee-uid',
		accepted: false,
	}

	const tempExternalFile = {
		id: 65,
		share_type: 0,
		parent: -1,
		remote: 'http://nextcloud1.local/',
		remote_id: '71',
		share_token: '9GpiAmTIjayclrE',
		name: '/test.md',
		owner: 'owner-uid',
		user: 'sharee-uid',
		mountpoint: '{{TemporaryMountPointName#/test.md}}',
		accepted: 0,
	}

	beforeEach(() => { vi.resetAllMocks() })

	test('File', async () => {
		axios.get.mockReturnValueOnce(Promise.resolve({
			data: {
				ocs: {
					data: [shareFile],
				},
			},
		}))

		const shares = await getContents(false, true, false, false)

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(shares.contents).toHaveLength(1)

		const file = shares.contents[0] as File
		expect(file).toBeInstanceOf(File)
		expect(file.fileid).toBe(530936)
		expect(file.source).toBe('http://nextcloud.local/remote.php/dav/files/test/document.md')
		expect(file.owner).toBe('test')
		expect(file.mime).toBe('text/markdown')
		expect(file.mtime).toBeInstanceOf(Date)
		expect(file.size).toBe(123)
		expect(file.permissions).toBe(27)
		expect(file.root).toBe('/files/test')
		expect(file.attributes).toBeInstanceOf(Object)
		expect(file.attributes['has-preview']).toBe(true)
		expect(file.attributes.sharees).toEqual({
			sharee: {
				id: 'user00',
				'display-name': 'User00',
				type: 0,
			},
		})
		expect(file.attributes.favorite).toBe(0)
	})

	test('Folder', async () => {
		axios.get.mockReturnValueOnce(Promise.resolve({
			data: {
				ocs: {
					data: [shareFolder],
				},
			},
		}))

		const shares = await getContents(false, true, false, false)

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(shares.contents).toHaveLength(1)

		const folder = shares.contents[0] as Folder
		expect(folder).toBeInstanceOf(Folder)
		expect(folder.fileid).toBe(531080)
		expect(folder.source).toBe('http://nextcloud.local/remote.php/dav/files/test/Folder')
		expect(folder.owner).toBe('test')
		expect(folder.mime).toBe('httpd/unix-directory')
		expect(folder.mtime).toBeInstanceOf(Date)
		expect(folder.size).toBe(0)
		expect(folder.permissions).toBe(31)
		expect(folder.root).toBe('/files/test')
		expect(folder.attributes).toBeInstanceOf(Object)
		expect(folder.attributes['has-preview']).toBe(false)
		expect(folder.attributes.previewUrl).toBeUndefined()
		expect(folder.attributes.favorite).toBe(1)
	})

	describe('Remote file', () => {
		test('Accepted', async () => {
			axios.get.mockReturnValueOnce(Promise.resolve({
				data: {
					ocs: {
						data: [remoteFileAccepted],
					},
				},
			}))

			const shares = await getContents(false, true, false, false)

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(shares.contents).toHaveLength(1)

			const file = shares.contents[0] as File
			expect(file).toBeInstanceOf(File)
			expect(file.fileid).toBe(1234)
			expect(file.source).toBe('http://nextcloud.local/remote.php/dav/files/test/shares/test.md')
			expect(file.owner).toBe('owner-uid')
			expect(file.mime).toBe('text/markdown')
			expect(file.mtime?.getTime()).toBe(remoteFileAccepted.mtime * 1000)
			// not available for remote shares
			expect(file.size).toBe(undefined)
			expect(file.permissions).toBe(19)
			expect(file.root).toBe('/files/test')
			expect(file.attributes).toBeInstanceOf(Object)
			expect(file.attributes.favorite).toBe(0)
		})

		test('Pending', async () => {
			axios.get.mockReturnValueOnce(Promise.resolve({
				data: {
					ocs: {
						data: [remoteFilePending],
					},
				},
			}))

			const shares = await getContents(false, true, false, false)

			expect(axios.get).toHaveBeenCalledTimes(1)
			expect(shares.contents).toHaveLength(1)

			const file = shares.contents[0] as File
			expect(file).toBeInstanceOf(File)
			expect(file.fileid).toBe(1234)
			expect(file.source).toBe('http://nextcloud.local/remote.php/dav/files/test/shares/test.md')
			expect(file.owner).toBe('owner-uid')
			expect(file.mime).toBe('text/markdown')
			expect(file.mtime?.getTime()).toBe(remoteFilePending.mtime * 1000)
			// not available for remote shares
			expect(file.size).toBe(undefined)
			expect(file.permissions).toBe(0)
			expect(file.root).toBe('/files/test')
			expect(file.attributes).toBeInstanceOf(Object)
			expect(file.attributes.favorite).toBe(0)
		})
	})

	test('External temp file', async () => {
		axios.get.mockReturnValueOnce(Promise.resolve({
			data: {
				ocs: {
					data: [tempExternalFile],
				},
			},
		}))

		const shares = await getContents(false, true, false, false)

		expect(axios.get).toHaveBeenCalledTimes(1)
		expect(shares.contents).toHaveLength(1)

		const file = shares.contents[0] as File
		expect(file).toBeInstanceOf(File)
		expect(file.fileid).toBe(65)
		expect(file.source).toBe('http://nextcloud.local/remote.php/dav/files/test/test.md')
		expect(file.owner).toBe('owner-uid')
		expect(file.mime).toBe('text/markdown')
		expect(file.mtime?.getTime()).toBe(undefined)
		// not available for remote shares
		expect(file.size).toBe(undefined)
		expect(file.permissions).toBe(0)
		expect(file.root).toBe('/files/test')
		expect(file.attributes).toBeInstanceOf(Object)
		expect(file.attributes.favorite).toBe(0)
	})

	test('Empty', async () => {
		vi.spyOn(logger, 'error').mockImplementationOnce(() => {})
		axios.get.mockReturnValueOnce(Promise.resolve({
			data: {
				ocs: {
					data: [],
				},
			},
		}))

		const shares = await getContents(false, true, false, false)
		expect(shares.contents).toHaveLength(0)
		expect(logger.error).toHaveBeenCalledTimes(0)
	})

	test('Error', async () => {
		vi.spyOn(logger, 'error').mockImplementationOnce(() => {})
		axios.get.mockReturnValueOnce(Promise.resolve({
			data: {
				ocs: {
					data: [null],
				},
			},
		}))

		const shares = await getContents(false, true, false, false)
		expect(shares.contents).toHaveLength(0)
		expect(logger.error).toHaveBeenCalledTimes(1)
	})
})
