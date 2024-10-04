<?php

namespace Scanfully\Profiler\Data;

interface ProfilingInterface {
	public function start(): void;
	public function stop(): void;
	public function running(): bool;
}