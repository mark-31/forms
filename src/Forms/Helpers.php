<?php

/**
 * This file is part of the Nette Framework (https://nette.org)
 * Copyright (c) 2004 David Grudl (https://davidgrudl.com)
 */

declare(strict_types=1);

namespace Nette\Forms;

use Nette;
use Nette\Utils\Strings;
use Nette\Utils\Html;


/**
 * Forms helpers.
 */
class Helpers
{
	use Nette\StaticClass;

	private static $unsafeNames = [
		'attributes', 'children', 'elements', 'focus', 'length', 'reset', 'style', 'submit', 'onsubmit', 'form',
		'presenter', 'action',
	];


	/**
	 * Extracts and sanitizes submitted form data for single control.
	 * @param  int $type  type Form::DATA_TEXT, DATA_LINE, DATA_FILE, DATA_KEYS
	 * @return string|string[]
	 * @internal
	 */
	public static function extractHttpData(array $data, string $htmlName, int $type)
	{
		$name = explode('[', str_replace(['[]', ']', '.'], ['', '', '_'], $htmlName));
		$data = Nette\Utils\Arrays::get($data, $name, NULL);
		$itype = $type & ~Form::DATA_KEYS;

		if (substr($htmlName, -2) === '[]') {
			if (!is_array($data)) {
				return [];
			}
			foreach ($data as $k => $v) {
				$data[$k] = $v = static::sanitize($itype, $v);
				if ($v === NULL) {
					unset($data[$k]);
				}
			}
			if ($type & Form::DATA_KEYS) {
				return $data;
			}
			return array_values($data);
		} else {
			return static::sanitize($itype, $data);
		}
	}


	private static function sanitize(int $type, $value)
	{
		if ($type === Form::DATA_TEXT) {
			return is_scalar($value) ? Strings::normalizeNewLines($value) : NULL;

		} elseif ($type === Form::DATA_LINE) {
			return is_scalar($value) ? Strings::trim(strtr((string) $value, "\r\n", '  ')) : NULL;

		} elseif ($type === Form::DATA_FILE) {
			return $value instanceof Nette\Http\FileUpload ? $value : NULL;

		} else {
			throw new Nette\InvalidArgumentException('Unknown data type');
		}
	}


	/**
	 * Converts control name to HTML name.
	 */
	public static function generateHtmlName(string $id): string
	{
		$name = str_replace(Nette\ComponentModel\IComponent::NAME_SEPARATOR, '][', $id, $count);
		if ($count) {
			$name = substr_replace($name, '', strpos($name, ']'), 1) . ']';
		}
		if (is_numeric($name) || in_array($name, self::$unsafeNames, TRUE)) {
			$name = '_' . $name;
		}
		return $name;
	}


	public static function exportRules(Rules $rules): array
	{
		$payload = [];
		foreach ($rules as $rule) {
			if (!is_string($op = $rule->validator)) {
				if (!Nette\Utils\Callback::isStatic($op)) {
					continue;
				}
				$op = Nette\Utils\Callback::toString($op);
			}
			if ($rule->branch) {
				$item = [
					'op' => ($rule->isNegative ? '~' : '') . $op,
					'rules' => static::exportRules($rule->branch),
					'control' => $rule->control->getHtmlName(),
				];
				if ($rule->branch->getToggles()) {
					$item['toggle'] = $rule->branch->getToggles();
				} elseif (!$item['rules']) {
					continue;
				}
			} else {
				$item = ['op' => ($rule->isNegative ? '~' : '') . $op, 'msg' => Validator::formatMessage($rule, FALSE)];
			}

			if (is_array($rule->arg)) {
				$item['arg'] = [];
				foreach ($rule->arg as $key => $value) {
					$item['arg'][$key] = $value instanceof IControl ? ['control' => $value->getHtmlName()] : $value;
				}
			} elseif ($rule->arg !== NULL) {
				$item['arg'] = $rule->arg instanceof IControl ? ['control' => $rule->arg->getHtmlName()] : $rule->arg;
			}

			$payload[] = $item;
		}
		if ($payload && $rules->isOptional()) {
			array_unshift($payload, ['op' => 'optional']);
		}
		return $payload;
	}


	public static function createInputList(array $items, array $inputAttrs = NULL, array $labelAttrs = NULL, $wrapper = NULL): string
	{
		[$inputAttrs, $inputTag] = self::prepareAttrs($inputAttrs, 'input');
		[$labelAttrs, $labelTag] = self::prepareAttrs($labelAttrs, 'label');
		$res = '';
		$input = Html::el();
		$label = Html::el();
		[$wrapper, $wrapperEnd] = $wrapper instanceof Html ? [$wrapper->startTag(), $wrapper->endTag()] : [(string) $wrapper, ''];

		foreach ($items as $value => $caption) {
			foreach ($inputAttrs as $k => $v) {
				$input->attrs[$k] = $v[$value] ?? NULL;
			}
			foreach ($labelAttrs as $k => $v) {
				$label->attrs[$k] = $v[$value] ?? NULL;
			}
			$input->value = $value;
			$res .= ($res === '' && $wrapperEnd === '' ? '' : $wrapper)
				. $labelTag . $label->attributes() . '>'
				. $inputTag . $input->attributes() . (Html::$xhtml ? ' />' : '>')
				. ($caption instanceof Nette\Utils\IHtmlString ? $caption : htmlspecialchars($caption, ENT_NOQUOTES, 'UTF-8'))
				. '</label>'
				. $wrapperEnd;
		}
		return $res;
	}


	public static function createSelectBox(array $items, array $optionAttrs = NULL, $selected = NULL): Html
	{
		if ($selected !== NULL) {
			$optionAttrs['selected?'] = $selected;
		}
		[$optionAttrs, $optionTag] = self::prepareAttrs($optionAttrs, 'option');
		$option = Html::el();
		$res = $tmp = '';
		foreach ($items as $group => $subitems) {
			if (is_array($subitems)) {
				$res .= Html::el('optgroup')->label($group)->startTag();
				$tmp = '</optgroup>';
			} else {
				$subitems = [$group => $subitems];
			}
			foreach ($subitems as $value => $caption) {
				$option->value = $value;
				foreach ($optionAttrs as $k => $v) {
					$option->attrs[$k] = $v[$value] ?? NULL;
				}
				if ($caption instanceof Html) {
					$caption = clone $caption;
					$res .= $caption->setName('option')->addAttributes($option->attrs);
				} else {
					$res .= $optionTag . $option->attributes() . '>'
						. htmlspecialchars((string) $caption, ENT_NOQUOTES, 'UTF-8')
						. '</option>';
				}
			}
			$res .= $tmp;
			$tmp = '';
		}
		return Html::el('select')->setHtml($res);
	}


	private static function prepareAttrs(?array $attrs, string $name): array
	{
		$dynamic = [];
		foreach ((array) $attrs as $k => $v) {
			$p = str_split($k, strlen($k) - 1);
			if ($p[1] === '?' || $p[1] === ':') {
				unset($attrs[$k], $attrs[$p[0]]);
				if ($p[1] === '?') {
					$dynamic[$p[0]] = array_fill_keys((array) $v, TRUE);
				} elseif (is_array($v) && $v) {
					$dynamic[$p[0]] = $v;
				} else {
					$attrs[$p[0]] = $v;
				}
			}
		}
		return [$dynamic, '<' . $name . Html::el(NULL, $attrs)->attributes()];
	}

}
