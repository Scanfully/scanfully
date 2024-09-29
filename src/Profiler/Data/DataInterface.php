<?php

namespace Scanfully\Profiler\Data;

interface DataInterface {
	public function add( DataInterface $data );

	public function data(): array;
}