<?php

declare(strict_types=1);

namespace App\FrontModule\Presenters;

use App\Model;
use App\Model\UserRepository;
use App\Model\DrugsRepository;
use Nette\Application\UI\Form;
use Nette\Utils\ArrayHash;
use DateTime;
use ActionLocker;

/////////////////////// FRONT: DEFAULT PRESENTER ///////////////////////

final class DefaultPresenter extends BasePresenter
{
	/** @var Model\ArticlesRepository */
	private $articles;

	private $userRepository;
	private $drugsRepository;

	public function __construct(
		UserRepository $userRepository,
		DrugsRepository $drugsRepository,
		Model\ArticlesRepository $articles
	)
	{
		$this->articles = $articles;
		$this->userRepository = $userRepository;
		$this->drugsRepository = $drugsRepository;
	}

	protected function startup()
	{
			parent::startup();
			if ($this->user->isLoggedIn()) {
				$player = $this->userRepository->getUser($this->user->getIdentity()->id);
				if ($player->tutorial == 0) {
					$this->redirect('Intro:');
				}
			}
	}

	public function renderDefault()
	{
		$this->template->articles = $this->articles->findAll();
		if ($this->user->isLoggedIn()) {
			$player = $this->userRepository->getUser($this->user->getIdentity()->id);
			$this->template->user = $player;
			$avatars = [];
			for ($i = 1; $i <= 21; $i++) {
				$avatars[$i] = $i;
			}
			$newStats = $this->userRepository->getUser($player->id);
			$this->template->avatars = $avatars;
			$this->template->userAvatar = $player->avatar;
			$xp = $newStats->player_stats->xp;
			$this->template->xp = $xp;
			$xpMax = $newStats->player_stats->xp_max;
			$xpMin = $newStats->player_stats->xp_min;
			$this->template->xpMax = $xpMax;
			$this->template->xpMin = $xpMin;

			$drugsInventory = $this->drugsRepository->findDrugInventory($player->id)->order('drugs_id', 'ASC')->fetchAll();
			if (count($drugsInventory) > 0) {
				$this->template->drugsInventory = $drugsInventory;
			} else {
				$drugs = $this->drugsRepository->findAll();
				$this->template->drugs = $drugs;
			}

			// Leaderboard
			$lastPage = 0;
			$page = 1;
			$leaderboard = $this->userRepository->findUsers()->page($page, 10, $lastPage);
			$this->template->users = $leaderboard;
			$this->template->lastPage = $lastPage;
			$this->template->page = $page;
		} else {
			$drugs = $this->drugsRepository->findAll();
			$this->template->drugs = $drugs;
		}
	}

	public function renderTraining()
	{
		if ($this->user->isLoggedIn()) {
			$player = $this->userRepository->getUser($this->user->getIdentity()->id);
			$actionLocker = new ActionLocker();
			$actionLocker->checkActions($player, $this);
			$this->template->user = $player;
			$xp = $player->player_stats->xp;
			$xpMax = $player->player_stats->xp_max;
			$xpMin = $player->player_stats->xp_min;
			$this->template->skillpoints = $player->skillpoints;
			$this->template->progressValue = round((($xp - $xpMin) / ($xpMax - $xpMin)) * (100));

			$strengthCost = round($player->player_stats->strength * 0.75);
			$staminaCost = round($player->player_stats->stamina * 0.75);
			$speedCost = round($player->player_stats->speed * 0.75);
			$this->template->strengthCost = $strengthCost;
			$this->template->staminaCost = $staminaCost;
			$this->template->speedCost = $speedCost;
			$isTraining = $player->actions->training;
			$this->template->isTraining = $isTraining;
			if ($isTraining > 0) {
				$trainingUntil = $player->actions->training_end;
				$now = new DateTime();
				$diff = $trainingUntil->getTimestamp() - $now->getTimestamp();
				if ($diff >= 0) {
					$s = $diff % 60;
					$m = $diff / 60 % 60;
					$h = $diff / 3600 % 60;
					$this->template->hours = $h > 9 ? $h : '0'.$h;
					$this->template->minutes = $m > 9 ? $m : '0'.$m;
					$this->template->seconds = $s > 9 ? $s : '0'.$s;
					$this->template->trainingUntil = $trainingUntil;
				} else {
					$this->endTraining($isTraining);
					$isTraining = 0;
					$this->redirect('this');
				}
			}
		} else {
			$this->redirect('Login:default');
		}
	}

	public function renderRest() {
		if ($this->user->isLoggedIn()) {
			$player = $this->userRepository->getUser($this->user->getIdentity()->id);
			$actionLocker = new ActionLocker();
			$actionLocker->checkActions($player, $this);
			$this->template->user = $player;
			$isResting = $player->actions->resting;
			$this->template->resting = $isResting;
			if ($isResting) {
				$restingSince = $player->actions->resting_start;
				$this->template->restingSince = $restingSince;
				$nowDate = new DateTime();
				$diff = abs($restingSince->getTimestamp() - $nowDate->getTimestamp());
				if ($diff < 3600) {
					$this->template->timePassed = round($diff / 60) . ' minutes';
				} else if ($diff <= 5400) {
					$this->template->timePassed = round($diff / 3600) . ' hour';
				} else {
					$this->template->timePassed = round($diff / 3600) . ' hours';
				}
			}
		} else {
			$this->redirect('Login:default');
		}
	}

	public function createComponentRestForm(): Form {
		$form = new Form();
		$form->addSubmit('rest', 'Rest');
		$form->addSubmit('wakeup', 'Stop resting');
		$form->onSuccess[] = [$this, 'restFormSucceeded'];
		return $form;
	}

	public function restFormSucceeded(Form $form, $values): void {
		$control = $form->isSubmitted();
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$isResting = $player->actions->resting;
		if ($control->name == 'rest') {
			if ($isResting <= 0) {
				$playerRestStart = new DateTime();
				$this->userRepository->getUser($player->id)->actions->update([
					'resting' => 1,
					'resting_start' => $playerRestStart
				]);
				$this->flashMessage('You went to rest', 'success');
				$this->redirect('this');
			}
		} else if ($control->name == 'wakeup') {
			if ($isResting > 0) {
				$restingSince = $this->userRepository->getUser($player->id)->actions->resting_start;
				$nowDate = new DateTime();
				$diff = abs($restingSince->getTimestamp() - $nowDate->getTimestamp());
				$this->userRepository->getUser($player->id)->actions->update([
					'resting' => 0
				]);
				$reward = 25 * round($diff / 3600);
				if ($reward > 0) {
					if ($player->energy + $reward > $player->energy_max) {
						$this->userRepository->getUser($player->id)->player_stats->update([
							'energy=' => $player->energy_max
						]);
					} else {
						$this->userRepository->getUser($player->id)->player_stats->update([
							'energy+=' => $reward
						]);
					}
				}
				$this->flashMessage('You regained ' . $reward . ' energy', 'success');
				$this->redirect('this');
			}
		}
	}

	private function endTraining($trainingStat) {
		switch ($trainingStat) {
			case 1:
				$this->userRepository->updateStatsAdd($this->user->getIdentity()->id, 1, 0, 0);
			break;
			case 2:
				$this->userRepository->updateStatsAdd($this->user->getIdentity()->id, 0, 1, 0);
			break;
			case 3:
				$this->userRepository->updateStatsAdd($this->user->getIdentity()->id, 0, 0, 1);
			break;
		}
		$this->userRepository->getUser($this->user->getIdentity()->id)->actions->update([
			'training' => 0
		]);
	}

	public function createComponentTrainingForm(): Form {
		$form = new Form();
		$form->setHtmlAttribute('id', 'trainingForm');
		$form->addSubmit('strength', 'Train');
		$form->addSubmit('stamina', 'Train');
		$form->addSubmit('speed', 'Train');
		$form->onSuccess[] = [$this, 'trainingFormSucceeded'];
		return $form;
	}

	public function trainingFormSucceeded(Form $form, $values): void {
		$control = $form->isSubmitted();
		$trainNumber = 0;
		$trainSkill = '';
		switch ($control->name) {
			case 'strength':
				$trainNumber = 1;
				$trainSkill = 'strength';
			break;
			case 'stamina':
				$trainNumber = 2;
				$trainSkill = 'stamina';
			break;
			case 'speed':
				$trainNumber = 3;
				$trainSkill = 'speed';
			break;
		}
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		if ($player->actions->training == 0 && $trainNumber != 0) {
			// Training cost = skill level * 0.75
			$trainingCost = round($player->player_stats[$trainSkill] * 0.75);
			$currentMoney = $player->money;
			// Energy cost = 10
			$currentEnergy = $player->player_stats->energy;
			if ($currentMoney >= $trainingCost) {
				if ($currentEnergy >= 10) {
					$currentMoney -= $trainingCost;
					$currentEnergy -= 10;
					$now = new DateTime();
					$trainingEndTS = $now->getTimestamp();
					// Training time = 30 minutes = 1800s
					$trainingEndTS += 1800;
					$now->setTimestamp($trainingEndTS);
					$trainingEnd = $now->format('Y-m-d H:i:s');
					$this->userRepository->getUser($this->user->getIdentity()->id)->update([
						'money-=' => $trainingCost
					]);
					$this->userRepository->getUser($this->user->getIdentity()->id)->player_stats->update([
						'energy-=' => 10
					]);
					$this->userRepository->getUser($this->user->getIdentity()->id)->actions->update([
						'training' => $trainNumber,
						'training_end' => $trainingEnd
					]);
					$this->flashMessage('Training started', 'success');
					$this->redirect('this');
				} else {
					$this->flashMessage('Not enough energy', 'danger');
					$this->redirect('this');
				}
			} else {
				$this->flashMessage('Not enough money', 'danger');
				$this->redirect('this');
			}
		}
	}

	public function createComponentSkillpointsForm(): Form {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$form = new Form();
		$form->setHtmlAttribute('id', 'skillpointsForm');
		$form->addHidden('strength', '0')
				 ->setHtmlAttribute('data-stat-hidden', 'strength')
				 ->setHtmlId('hidden-1')
				 ->setDefaultValue(0)
				 ->setHtmlAttribute('data-extra-value', '0');
		$form->addHidden('stamina', '0')
				 ->setHtmlAttribute('data-stat-hidden', 'stamina')
				 ->setHtmlId('hidden-2')
				 ->setDefaultValue(0)
				 ->setHtmlAttribute('data-extra-value', '0');
		$form->addHidden('speed', '0')
				 ->setHtmlAttribute('data-stat-hidden', 'speed')
				 ->setHtmlId('hidden-3')
				 ->setDefaultValue(0)
				 ->setHtmlAttribute('data-extra-value', '0');
		$form->addHidden('usedSp', '0')
				 ->setHtmlAttribute('data-stat-hidden', 'skillpoints')
				 ->setHtmlId('hidden-4')
				 ->setDefaultValue(0)
				 ->setHtmlAttribute('data-extra-value', '0');
		$form->addSubmit('save', 'Confirm');
		$form->onSuccess[] = [$this, 'skillpointsFormSucceeded'];
		return $form;
	}

	public function skillpointsFormSucceeded(Form $form, $values): void {
		$player = $this->userRepository->getUser($this->user->getIdentity()->id);
		$strength = intval($values->strength);
		$stamina = intval($values->stamina);
		$speed = intval($values->speed);
		$usedSp = intval($values->usedSp);
		$statsTotal = $strength + $stamina + $speed;
		if ($usedSp > 0) {
			if ($statsTotal == $usedSp && $usedSp <= $player->skillpoints && $player->skillpoints > 0) {
				$this->userRepository->getUser($player->id)->update([
					'skillpoints-=' => $usedSp
				]);
				$this->userRepository->updateStatsAdd($player->id, $strength, $stamina, $speed);
				$this->flashMessage('Skillpoints successfully assigned', 'success');
				$this->redirect('this');
			} else {
				$this->flashMessage('Invalid stats, try again.', 'danger');
				$this->redirect('this');
			}
		}
	}

	public function createComponentAvatarForm(): Form
	{
		$avatars = [];
		for ($i = 1; $i <= 21; $i++) {
			$avatars[$i] = $i;
		}
		$form = new Form();
		$form->addRadioList('avatar', 'Choose an avatar from the list:', $avatars);
		$form->addSubmit('save', 'Save');
		$form->onSuccess[] = [$this, 'avatarFormSucceeded'];
		return $form;
	}

	public function avatarFormSucceeded(Form $form, $values): void {
		$selected = $values->avatar;
		if ($selected >= 1 && $selected <= 21) {
			$player = $this->user->getIdentity();
			if ($player) {
				$player->avatar = $selected;
				$this->userRepository->getUser($player->id)->update([
					'avatar' => $selected
				]);
				$this->flashMessage('Avatar changed', 'success');
				$this->redirect('this');
			}
		}
	}
}
