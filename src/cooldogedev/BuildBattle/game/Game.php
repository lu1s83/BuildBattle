<?php

/**
 * Copyright (c) 2022 cooldogedev
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 *
 * @auto-license
 */

declare(strict_types=1);

namespace cooldogedev\BuildBattle\game;

use cooldogedev\BuildBattle\async\BackgroundTaskPool;
use cooldogedev\BuildBattle\async\directory\AsyncDirectoryClone;
use cooldogedev\BuildBattle\async\directory\AsyncDirectoryDelete;
use cooldogedev\BuildBattle\BuildBattle;
use cooldogedev\BuildBattle\game\data\GameData;
use cooldogedev\BuildBattle\game\handler\EndHandler;
use cooldogedev\BuildBattle\game\handler\IHandler;
use cooldogedev\BuildBattle\game\handler\PreStartHandler;
use cooldogedev\BuildBattle\game\player\PlayerManager;
use cooldogedev\BuildBattle\game\plot\PlotManager;
use cooldogedev\BuildBattle\session\Session;
use cooldogedev\BuildBattle\utility\message\KnownMessages;
use cooldogedev\BuildBattle\utility\message\LanguageManager;
use cooldogedev\BuildBattle\utility\message\TranslationKeys;
use cooldogedev\BuildBattle\utility\Utils;
use pocketmine\utils\TextFormat;
use pocketmine\world\World;

final class Game
{
    protected const POSITION_MAP = [
        0 => KnownMessages::END_FIRST,
        1 => KnownMessages::END_SECOND,
        2 => KnownMessages::END_THIRD,
    ];

    /**
     * For identifying worlds generated by the plugin.
     * can be used for things such as cleaning up the worlds during startup.
     */
    public const GAME_WORLD_IDENTIFIER = "BB-GAME-";
    public const GAME_LOBBY_IDENTIFIER = Game::GAME_WORLD_IDENTIFIER . "-LOBBY-";

    protected ?PlayerManager $playerManager;
    protected ?PlotManager $plotManager;
    protected ?IHandler $handler;

    protected ?Session $winner = null;
    protected ?World $world = null;
    protected ?World $lobby = null;
    protected bool $loading = true;
    protected ?string $theme = null;

    public function __construct(protected BuildBattle $plugin, protected ?GameData $data)
    {
        $this->playerManager = new PlayerManager($this);
        $this->plotManager = new PlotManager($this);
        $this->handler = new PreStartHandler($this);

        $directories = [];

        $directories[$plugin->getDataFolder() . "maps" . DIRECTORY_SEPARATOR . $this->getData()->getName() . DIRECTORY_SEPARATOR . GameData::GAME_DATA_WORLD] = $plugin->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $this->data->getWorld();

        if ($this->hasSeparateLobby()) {
            $directories[$this->plugin->getDataFolder() . "maps" . DIRECTORY_SEPARATOR . $this->getData()->getName() . DIRECTORY_SEPARATOR . GameData::GAME_DATA_LOBBY] = $plugin->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $this->data->getLobby();
        }

        $task = new AsyncDirectoryClone($directories);
        $task->setClosure(
            function () use (&$plugin): void {
                if ($plugin->getServer()->getWorldManager()->loadWorld($this->data->getWorld())) {
                    $this->world = $plugin->getServer()->getWorldManager()->getWorldByName($this->data->getWorld());
                    $this->world->setSpawnLocation($this->world->getSpawnLocation()->add(0.5, 0, 0.5));
                } else {
                    $plugin->getLogger()->debug("Failed to load world " . $this->data->getWorld() . " of arena " . $this->getData()->getId());
                    $this->startDestruction();

                    return;
                }

                if ($this->hasSeparateLobby() && $plugin->getServer()->getWorldManager()->loadWorld($this->data->getLobby())) {
                    $this->lobby = $plugin->getServer()->getWorldManager()->getWorldByName($this->data->getLobby());
                    $this->lobby->setSpawnLocation($this->lobby->getSpawnLocation()->add(0.5, 0, 0.5));
                } else {
                    $plugin->getLogger()->debug("Failed to load lobby " . $this->data->getLobby() . " of arena " . $this->getData()->getId() . " used the game world as the lobby: " . $this->data->getWorld());
                    $this->lobby = $this->world;
                }

                $this->world->setTime(World::TIME_DAY);
                $this->world->stopTime();
                $this->world->setAutoSave(false);
                $this->lobby->setTime(World::TIME_DAY);
                $this->lobby->stopTime();
                $this->lobby->setAutoSave(false);

                foreach ($this->data->getSpawns() as $id => [$min, $max]) {
                    $this->plotManager->addPlot($id, Utils::parseVec3($min), Utils::parseVec3($max), $this->data->getBuildHeight());
                }

                $this->loading = false;
                $this->playerManager->clearQueue();
            }
        );

        BackgroundTaskPool::getInstance()->submitTask($task);
    }

    public function getData(): GameData
    {
        return $this->data;
    }

    public function getWorld(): ?World
    {
        return $this->world;
    }

    public function hasSeparateLobby(): bool
    {
        return strtolower($this->data->getLobby()) !== strtolower($this->data->getWorld()) && file_exists($this->plugin->getDataFolder() . "maps" . DIRECTORY_SEPARATOR . $this->getData()->getName() . DIRECTORY_SEPARATOR . GameData::GAME_DATA_LOBBY);
    }

    public function getLobby(): ?World
    {
        return $this->lobby;
    }

    public function startDestruction(): void
    {
        foreach ($this->playerManager->getSessions() as $session) {
            $this->playerManager->removeFromGame($session);
        }

        $this->plugin->getGameManager()->removeGame($this->getData()->getId());
        $this->world !== null && $this->plugin->getServer()->getWorldManager()->unloadWorld($this->world, true);

        $directories = [];
        $directories[] = $this->plugin->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $this->data->getWorld();

        if ($this->hasSeparateLobby()) {
            $this->lobby !== null && $this->plugin->getServer()->getWorldManager()->unloadWorld($this->lobby, true);
            $directories[] = $this->plugin->getServer()->getDataPath() . "worlds" . DIRECTORY_SEPARATOR . $this->data->getLobby();
        }

        $task = new AsyncDirectoryDelete($directories);

        // Copy the id because we set the data to null afterwards.
        $id = $this->getData()->getId();
        $task->setClosure(fn() => $this->plugin->getLogger()->debug("Deleted bb-" . $id . " game."));
        BackgroundTaskPool::getInstance()->submitTask($task);

        $this->handler = null;
        $this->playerManager = null;
        $this->plotManager = null;
        $this->world = null;
        $this->winner = null;
        $this->data = null;
        $this->loading = true;
    }

    public function getPlotManager(): ?PlotManager
    {
        return $this->plotManager;
    }

    public function getPlugin(): BuildBattle
    {
        return $this->plugin;
    }

    public function isFree(bool $fromQueue = false): bool
    {
        if (
            !$this->handler instanceof PreStartHandler ||
            !$this->loading && $this->plotManager->getRandomPlot() === null ||
            count($this->playerManager->getSessions()) >= $this->getData()->getMaxPlayers() ||
            $fromQueue && count($this->playerManager->getQueues()) >= $this->getData()->getMaxPlayers()
        ) {
            return false;
        }
        return true;
    }

    public function isLoading(): bool
    {
        return $this->loading;
    }

    public function getWinner(): ?Session
    {
        return $this->winner;
    }

    public function setWinner(?Session $winner): void
    {
        $this->winner = $winner;
    }

    public function tickGame(): void
    {
        if ($this->loading) {
            return;
        }

        if (count($this->playerManager->getSessions()) < 2 && !$this->handler instanceof EndHandler && !$this->handler instanceof PreStartHandler) {
            $sessions = $this->playerManager->getSessions();

            if (count($sessions) === 0) {
                $this->startDestruction();
            } else {
                $winner = $sessions[array_rand($sessions)];
                $winner->setState(Session::PLAYER_STATE_WAITING_RESULTS);
                $this->calculateResults();
            }

            return;
        }

        $this->getHandler()?->handleTicking();
        $this->getHandler()?->handleScoreboardUpdates();
    }

    public function calculateResults(): void
    {
        $scores = [];

        foreach ($this->playerManager->getSessions(Session::PLAYER_STATE_WAITING_RESULTS) as $uuid => $session) {
            $scores[$uuid] = [$session->getScore(), $session];
        }

        usort($scores, fn(array $a, array $b) => $b[0] <=> $a[0]);

        if (count($scores) > 0) {
            $this->winner = $scores[0][1];
        }

        $this->broadcastMessage(LanguageManager::getMessage(KnownMessages::TOPIC_END, KnownMessages::END_MESSAGE));

        for ($i = 0; $i < 3; $i++) {
            if (!isset($scores[$i])) {
                continue;
            }

            $data = $scores[$i];

            $this->broadcastMessage(LanguageManager::getMessage(KnownMessages::TOPIC_END, Game::POSITION_MAP[$i]), [
                TranslationKeys::PLAYER => $data[1]->getPlayer()->getDisplayName(),
                TranslationKeys::SCORE => $data[0],
            ]);
        }

        $translations = [
            TranslationKeys::THEME => $this->getTheme(),
            TranslationKeys::BUILDER => $this->winner->getPlayer()->getDisplayName(),
        ];

        $this->broadcastTitle(LanguageManager::getMessage(KnownMessages::TOPIC_PLOT_ENTER, KnownMessages::PLOT_ENTER_TITLE), LanguageManager::getMessage(KnownMessages::TOPIC_PLOT_ENTER, KnownMessages::PLOT_ENTER_SUBTITLE), $translations, stay: 40);
        $this->broadcastMessage(LanguageManager::getMessage(KnownMessages::TOPIC_PLOT_ENTER, KnownMessages::PLOT_ENTER_MESSAGE), $translations);

        foreach ($this->playerManager->getSessions() as $session) {
            $session->clearAll();

            if ($session !== $this->winner) {
                $session->addLoss(1);
                $session->setWinStreak(0);
            } else {
                $session->addWin(1);
                $session->addWinStreak(1);
            }

            $position = (int)array_search([$session->getScore(), $session], $scores);

            $session->getPlayer()->sendMessage(LanguageManager::translate(LanguageManager::getMessage(KnownMessages::TOPIC_END, KnownMessages::END_YOU), [
                TranslationKeys::POSITION => (string)($position + 1),
                TranslationKeys::SCORE => $session->getScore(),
            ]));

            $session->getPlayer()->teleport($this->winner->getPlot()->getCenter());
        }

        $this->setHandler(new EndHandler($this));
    }

    public function broadcastMessage(string $message, array $replacement = [], ?int $mode = null): void
    {
        if (trim(TextFormat::clean($message)) === "") {
            return;
        }

        $sessions = $this->playerManager->getSessions($mode);

        foreach ($sessions as $session) {
            $session->getPlayer()->sendMessage(LanguageManager::translate($message, $replacement));
        }
    }

    public function getTheme(): ?string
    {
        return $this->theme;
    }

    public function setTheme(?string $theme): void
    {
        $this->theme = $theme;
    }

    public function broadcastTitle(string $message, string $subtitle = "", array $replacement = [], ?int $mode = null, int $fadeIn = 5, int $stay = 20, int $fadeOut = 5): void
    {
        if (trim(TextFormat::clean($message)) === "") {
            return;
        }

        $sessions = $this->playerManager->getSessions($mode);

        foreach ($sessions as $session) {
            $session->getPlayer()->sendTitle(LanguageManager::translate($message, $replacement), LanguageManager::translate($subtitle, $replacement), $fadeIn, $stay, $fadeOut);
        }
    }

    public function getHandler(): ?IHandler
    {
        return $this->handler;
    }

    public function setHandler(IHandler $handler): void
    {
        $this->handler = $handler;
    }

    public function getPlayerManager(): ?PlayerManager
    {
        return $this->playerManager;
    }
}
