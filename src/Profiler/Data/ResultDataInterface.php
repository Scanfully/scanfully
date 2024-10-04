<?php

namespace Scanfully\Profiler\Data;

interface ResultDataInterface {
	public function add( ResultDataInterface $data );

	public function data(): array;
}