<?php

namespace Scanfully\Profiler\Ticks;

class Tick {
	public ?string $file;
	public ?int $line;
	public string $function;
	public ?string $class;
	public ?string $type;
	public ?array $args;

	public int $start;

	/**
	 * Create a new tick from a backtrace row
	 *
	 * @param  array $backtrace_row
	 *
	 * @return self
	 */
	public static final function from_backtrace( array $backtrace_row ): self {

		$tick           = new self();
		$tick->file     = $backtrace_row['file'] ?? null;
		$tick->line     = $backtrace_row['line'] ?? null;
		$tick->function = $backtrace_row['function'];
		$tick->class    = $backtrace_row['class'] ?? null;
		$tick->type     = $backtrace_row['type'] ?? null;
		$tick->args     = $backtrace_row['args'] ?? null;

		// start now
		$tick->start = microtime( true );

		return $tick;
	}

	/**
	 * Check if two ticks are equal
	 *
	 * @param  self $tick
	 *
	 * @return bool
	 */
	public final function same_function( self $tick ): bool {
		return $this->file === $tick->file && $this->function === $tick->function && $this->class === $tick->class && $this->type === $tick->type;
	}
}

