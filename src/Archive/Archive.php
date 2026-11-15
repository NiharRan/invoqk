<?php
namespace Invoqk\Archive;

final class Archive {
	public static ?Index $index = null;

	public static function index(): Index {
		return self::$index ??= new WpdbIndex();
	}

	public static function reset(): void {
		self::$index = null;
	}
}
