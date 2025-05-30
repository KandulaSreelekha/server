<?php

/**
 * SPDX-FileCopyrightText: 2017 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace Tests\Contacts\ContactsMenu;

use OC\Contacts\ContactsMenu\ActionProviderStore;
use OC\Contacts\ContactsMenu\ContactsStore;
use OC\Contacts\ContactsMenu\Entry;
use OC\Contacts\ContactsMenu\Manager;
use OCP\App\IAppManager;
use OCP\Constants;
use OCP\Contacts\ContactsMenu\IProvider;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use Test\TestCase;

class ManagerTest extends TestCase {
	/** @var ContactsStore|MockObject */
	private $contactsStore;

	/** @var IAppManager|MockObject */
	private $appManager;

	/** @var IConfig|MockObject */
	private $config;

	/** @var ActionProviderStore|MockObject */
	private $actionProviderStore;

	private Manager $manager;

	protected function setUp(): void {
		parent::setUp();

		$this->contactsStore = $this->createMock(ContactsStore::class);
		$this->actionProviderStore = $this->createMock(ActionProviderStore::class);
		$this->appManager = $this->createMock(IAppManager::class);
		$this->config = $this->createMock(IConfig::class);

		$this->manager = new Manager($this->contactsStore, $this->actionProviderStore, $this->appManager, $this->config);
	}

	private function generateTestEntries(): array {
		$entries = [];
		foreach (range('Z', 'A') as $char) {
			$entry = $this->createMock(Entry::class);
			$entry->expects($this->any())
				->method('getFullName')
				->willReturn('Contact ' . $char);
			$entries[] = $entry;
		}
		return $entries;
	}

	public function testGetFilteredEntries(): void {
		$filter = 'con';
		$user = $this->createMock(IUser::class);
		$entries = $this->generateTestEntries();
		$provider = $this->createMock(IProvider::class);

		$this->config->expects($this->exactly(2))
			->method('getSystemValueInt')
			->willReturnMap([
				['sharing.maxAutocompleteResults', Constants::SHARING_MAX_AUTOCOMPLETE_RESULTS_DEFAULT, 25],
				['sharing.minSearchStringLength', 0, 0],
			]);
		$this->contactsStore->expects($this->once())
			->method('getContacts')
			->with($user, $filter)
			->willReturn($entries);
		$this->actionProviderStore->expects($this->once())
			->method('getProviders')
			->with($user)
			->willReturn([$provider]);
		$provider->expects($this->exactly(25))
			->method('process');
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with($this->equalTo('contacts'), $user)
			->willReturn(false);
		$expected = [
			'contacts' => array_slice($entries, 0, 25),
			'contactsAppEnabled' => false,
		];

		$data = $this->manager->getEntries($user, $filter);

		$this->assertEquals($expected, $data);
	}

	public function testGetFilteredEntriesLimit(): void {
		$filter = 'con';
		$user = $this->createMock(IUser::class);
		$entries = $this->generateTestEntries();
		$provider = $this->createMock(IProvider::class);

		$this->config->expects($this->exactly(2))
			->method('getSystemValueInt')
			->willReturnMap([
				['sharing.maxAutocompleteResults', Constants::SHARING_MAX_AUTOCOMPLETE_RESULTS_DEFAULT, 3],
				['sharing.minSearchStringLength', 0, 0],
			]);
		$this->contactsStore->expects($this->once())
			->method('getContacts')
			->with($user, $filter)
			->willReturn($entries);
		$this->actionProviderStore->expects($this->once())
			->method('getProviders')
			->with($user)
			->willReturn([$provider]);
		$provider->expects($this->exactly(3))
			->method('process');
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with($this->equalTo('contacts'), $user)
			->willReturn(false);
		$expected = [
			'contacts' => array_slice($entries, 0, 3),
			'contactsAppEnabled' => false,
		];

		$data = $this->manager->getEntries($user, $filter);

		$this->assertEquals($expected, $data);
	}

	public function testGetFilteredEntriesMinSearchStringLength(): void {
		$filter = 'con';
		$user = $this->createMock(IUser::class);
		$provider = $this->createMock(IProvider::class);

		$this->config->expects($this->exactly(2))
			->method('getSystemValueInt')
			->willReturnMap([
				['sharing.maxAutocompleteResults', Constants::SHARING_MAX_AUTOCOMPLETE_RESULTS_DEFAULT, 3],
				['sharing.minSearchStringLength', 0, 4],
			]);
		$this->appManager->expects($this->once())
			->method('isEnabledForUser')
			->with($this->equalTo('contacts'), $user)
			->willReturn(false);
		$expected = [
			'contacts' => [],
			'contactsAppEnabled' => false,
		];

		$data = $this->manager->getEntries($user, $filter);

		$this->assertEquals($expected, $data);
	}

	public function testFindOne(): void {
		$shareTypeFilter = 42;
		$shareWithFilter = 'foobar';

		$user = $this->createMock(IUser::class);
		$entry = current($this->generateTestEntries());
		$provider = $this->createMock(IProvider::class);
		$this->contactsStore->expects($this->once())
			->method('findOne')
			->with($user, $shareTypeFilter, $shareWithFilter)
			->willReturn($entry);
		$this->actionProviderStore->expects($this->once())
			->method('getProviders')
			->with($user)
			->willReturn([$provider]);
		$provider->expects($this->once())
			->method('process');

		$data = $this->manager->findOne($user, $shareTypeFilter, $shareWithFilter);

		$this->assertEquals($entry, $data);
	}

	public function testFindOne404(): void {
		$shareTypeFilter = 42;
		$shareWithFilter = 'foobar';

		$user = $this->createMock(IUser::class);
		$provider = $this->createMock(IProvider::class);
		$this->contactsStore->expects($this->once())
			->method('findOne')
			->with($user, $shareTypeFilter, $shareWithFilter)
			->willReturn(null);
		$this->actionProviderStore->expects($this->never())
			->method('getProviders')
			->with($user)
			->willReturn([$provider]);
		$provider->expects($this->never())
			->method('process');

		$data = $this->manager->findOne($user, $shareTypeFilter, $shareWithFilter);

		$this->assertEquals(null, $data);
	}
}
