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

namespace cooldogedev\BuildBattle\game\plot;

use cooldogedev\BuildBattle\game\Game;
use pocketmine\math\Vector3;

final class PlotManager
{
    /**
     * @var Plot[]
     */
    protected ?array $plots = [];

    public function __construct(protected Game $game)
    {
    }

    public function getRandomPlot(): ?Plot
    {
        $availablePlots = [];

        /** @var Plot $plot */
        foreach ($this->plots as $plot) {
            if ($plot->isTaken()) {
                continue;
            }

            $availablePlots[] = $plot;
        }

        if (count($availablePlots) === 0) {
            return null;
        }

        return $availablePlots[array_rand($availablePlots)];
    }

    public function addPlot(int $id, Vector3 $min, Vector3 $max, int $height): void
    {
        $this->plots[$id] = new Plot($id, $this->game->getWorld(), $min, $max, $height);
    }

    public function getPlots(): ?array
    {
        return $this->plots;
    }
}
