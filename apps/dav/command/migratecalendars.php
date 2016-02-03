<?php

namespace OCA\Dav\Command;

use OCP\IUser;
use OCP\IUserManager;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCalendars extends Command {

	/** @var IUserManager */
	protected $userManager;

	/** @var \OCA\Dav\Migration\MigrateCalendars  */
	private $service;

	/**
	 * @param IUserManager $userManager
	 * @param \OCA\Dav\Migration\MigrateCalendars $service
	 */
	function __construct(IUserManager $userManager,
						 \OCA\Dav\Migration\MigrateCalendars $service
	) {
		parent::__construct();
		$this->userManager = $userManager;
		$this->service = $service;
	}

	protected function configure() {
		$this
			->setName('migrate:calendars')
			->setDescription('Migrate calendars from the calendar app to core')
			->addArgument('user',
				InputArgument::OPTIONAL,
				'User for whom all calendars will be migrated');
	}

	protected function execute(InputInterface $input, OutputInterface $output) {
		$this->service->setup();

		if ($input->hasArgument('user')) {
			$user = $input->getArgument('user');
			if (!$this->userManager->userExists($user)) {
				throw new \InvalidArgumentException("User <$user> in unknown.");
			}
			$this->service->migrateForUser($user);
		}
		$this->userManager->callForAllUsers(function($user) {
			/** @var IUser $user */
			$this->service->migrateForUser($user->getUID());
		});
	}
}
