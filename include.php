<?php
namespace s9e\TextFormatter;

abstract class Bundle
{
	public static function getCachedParser()
	{
		if (!isset(static::$parser))
		{
			static::$parser = static::getParser();
		}

		return static::$parser;
	}
	public static function getCachedRenderer()
	{
		if (!isset(static::$renderer))
		{
			static::$renderer = static::getRenderer();
		}

		return static::$renderer;
	}
	abstract public static function getParser();
	abstract public static function getRenderer();
	public static function getJS()
	{
		return '';
	}
	public static function parse($text)
	{
		if (isset(static::$beforeParse))
		{
			$text = call_user_func(static::$beforeParse, $text);
		}

		$xml = static::getCachedParser()->parse($text);

		if (isset(static::$afterParse))
		{
			$xml = call_user_func(static::$afterParse, $xml);
		}

		return $xml;
	}
	public static function render($xml, array $params = [])
	{
		$renderer = static::getCachedRenderer();

		if (!empty($params))
		{
			$renderer->setParameters($params);
		}

		if (isset(static::$beforeRender))
		{
			$xml = call_user_func(static::$beforeRender, $xml);
		}

		$output = $renderer->render($xml);

		if (isset(static::$afterRender))
		{
			$output = call_user_func(static::$afterRender, $output);
		}

		return $output;
	}
	public static function reset()
	{
		static::$parser   = null;
		static::$renderer = null;
	}
	public static function unparse($xml)
	{
		if (isset(static::$beforeUnparse))
		{
			$xml = call_user_func(static::$beforeUnparse, $xml);
		}

		$text = Unparser::unparse($xml);

		if (isset(static::$afterUnparse))
		{
			$text = call_user_func(static::$afterUnparse, $text);
		}

		return $text;
	}
}
namespace s9e\TextFormatter;

use InvalidArgumentException;
use RuntimeException;
use s9e\TextFormatter\Parser\FilterProcessing;
use s9e\TextFormatter\Parser\Logger;
use s9e\TextFormatter\Parser\Tag;

class Parser
{
	const RULE_AUTO_CLOSE        = 1 << 0;
	const RULE_AUTO_REOPEN       = 1 << 1;
	const RULE_BREAK_PARAGRAPH   = 1 << 2;
	const RULE_CREATE_PARAGRAPHS = 1 << 3;
	const RULE_DISABLE_AUTO_BR   = 1 << 4;
	const RULE_ENABLE_AUTO_BR    = 1 << 5;
	const RULE_IGNORE_TAGS       = 1 << 6;
	const RULE_IGNORE_TEXT       = 1 << 7;
	const RULE_IGNORE_WHITESPACE = 1 << 8;
	const RULE_IS_TRANSPARENT    = 1 << 9;
	const RULE_PREVENT_BR        = 1 << 10;
	const RULE_SUSPEND_AUTO_BR   = 1 << 11;
	const RULE_TRIM_FIRST_LINE   = 1 << 12;
	const RULES_AUTO_LINEBREAKS = self::RULE_DISABLE_AUTO_BR | self::RULE_ENABLE_AUTO_BR | self::RULE_SUSPEND_AUTO_BR;
	const RULES_INHERITANCE = self::RULE_ENABLE_AUTO_BR;
	const WHITESPACE = " \n\t";
	protected $cntOpen;
	protected $cntTotal;
	protected $context;
	protected $currentFixingCost;
	protected $currentTag;
	protected $isRich;
	protected $logger;
	public $maxFixingCost = 10000;
	protected $namespaces;
	protected $openTags;
	protected $output;
	protected $pos;
	protected $pluginParsers = [];
	protected $pluginsConfig;
	public $registeredVars = [];
	protected $rootContext;
	protected $tagsConfig;
	protected $tagStack;
	protected $tagStackIsSorted;
	protected $text;
	protected $textLen;
	protected $uid = 0;
	protected $wsPos;
	public function __construct(array $config)
	{
		$this->pluginsConfig  = $config['plugins'];
		$this->registeredVars = $config['registeredVars'];
		$this->rootContext    = $config['rootContext'];
		$this->tagsConfig     = $config['tags'];

		$this->__wakeup();
	}
	public function __sleep()
	{
		return ['pluginsConfig', 'registeredVars', 'rootContext', 'tagsConfig'];
	}
	public function __wakeup()
	{
		$this->logger = new Logger;
	}
	protected function reset($text)
	{
		if (!preg_match('//u', $text))
		{
			throw new InvalidArgumentException('Invalid UTF-8 input');
		}
		$text = preg_replace('/\\r\\n?/', "\n", $text);
		$text = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/S', '', $text);
		$this->logger->clear();
		$this->cntOpen           = [];
		$this->cntTotal          = [];
		$this->currentFixingCost = 0;
		$this->currentTag        = null;
		$this->isRich            = false;
		$this->namespaces        = [];
		$this->openTags          = [];
		$this->output            = '';
		$this->pos               = 0;
		$this->tagStack          = [];
		$this->tagStackIsSorted  = false;
		$this->text              = $text;
		$this->textLen           = strlen($text);
		$this->wsPos             = 0;
		$this->context = $this->rootContext;
		$this->context['inParagraph'] = false;
		++$this->uid;
	}
	protected function setTagOption($tagName, $optionName, $optionValue)
	{
		if (isset($this->tagsConfig[$tagName]))
		{
			$tagConfig = $this->tagsConfig[$tagName];
			unset($this->tagsConfig[$tagName]);
			$tagConfig[$optionName]     = $optionValue;
			$this->tagsConfig[$tagName] = $tagConfig;
		}
	}
	public function disableTag($tagName)
	{
		$this->setTagOption($tagName, 'isDisabled', true);
	}
	public function enableTag($tagName)
	{
		if (isset($this->tagsConfig[$tagName]))
		{
			unset($this->tagsConfig[$tagName]['isDisabled']);
		}
	}
	public function getLogger()
	{
		return $this->logger;
	}
	public function getText()
	{
		return $this->text;
	}
	public function parse($text)
	{
		$this->reset($text);
		$uid = $this->uid;
		$this->executePluginParsers();
		$this->processTags();
		$this->finalizeOutput();
		if ($this->uid !== $uid)
		{
			throw new RuntimeException('The parser has been reset during execution');
		}
		if ($this->currentFixingCost > $this->maxFixingCost)
		{
			$this->logger->warn('Fixing cost limit exceeded');
		}

		return $this->output;
	}
	public function setTagLimit($tagName, $tagLimit)
	{
		$this->setTagOption($tagName, 'tagLimit', $tagLimit);
	}
	public function setNestingLimit($tagName, $nestingLimit)
	{
		$this->setTagOption($tagName, 'nestingLimit', $nestingLimit);
	}
	protected function finalizeOutput()
	{
		$this->outputText($this->textLen, 0, true);
		do
		{
			$this->output = preg_replace('(<([^ />]++)[^>]*></\\1>)', '', $this->output, -1, $cnt);
		}
		while ($cnt > 0);
		if (strpos($this->output, '</i><i>') !== false)
		{
			$this->output = str_replace('</i><i>', '', $this->output);
		}
		$this->output = preg_replace('([\\x00-\\x08\\x0B-\\x1F])', '', $this->output);
		$this->output = Utils::encodeUnicodeSupplementaryCharacters($this->output);
		$tagName = ($this->isRich) ? 'r' : 't';
		$tmp = '<' . $tagName;
		foreach (array_keys($this->namespaces) as $prefix)
		{
			$tmp .= ' xmlns:' . $prefix . '="urn:s9e:TextFormatter:' . $prefix . '"';
		}

		$this->output = $tmp . '>' . $this->output . '</' . $tagName . '>';
	}
	protected function outputTag(Tag $tag)
	{
		$this->isRich = true;

		$tagName  = $tag->getName();
		$tagPos   = $tag->getPos();
		$tagLen   = $tag->getLen();
		$tagFlags = $tag->getFlags();

		if ($tagFlags & self::RULE_IGNORE_WHITESPACE)
		{
			$skipBefore = 1;
			$skipAfter  = ($tag->isEndTag()) ? 2 : 1;
		}
		else
		{
			$skipBefore = $skipAfter = 0;
		}
		$closeParagraph = false;
		if ($tag->isStartTag())
		{
			if ($tagFlags & self::RULE_BREAK_PARAGRAPH)
			{
				$closeParagraph = true;
			}
		}
		else
		{
			$closeParagraph = true;
		}
		$this->outputText($tagPos, $skipBefore, $closeParagraph);
		$tagText = ($tagLen)
		         ? htmlspecialchars(substr($this->text, $tagPos, $tagLen), ENT_NOQUOTES, 'UTF-8')
		         : '';
		if ($tag->isStartTag())
		{
			if (!($tagFlags & self::RULE_BREAK_PARAGRAPH))
			{
				$this->outputParagraphStart($tagPos);
			}
			$colonPos = strpos($tagName, ':');
			if ($colonPos)
			{
				$this->namespaces[substr($tagName, 0, $colonPos)] = 0;
			}
			$this->output .= '<' . $tagName;
			$attributes = $tag->getAttributes();
			ksort($attributes);

			foreach ($attributes as $attrName => $attrValue)
			{
				$this->output .= ' ' . $attrName . '="' . str_replace("\n", '&#10;', htmlspecialchars($attrValue, ENT_COMPAT, 'UTF-8')) . '"';
			}

			if ($tag->isSelfClosingTag())
			{
				if ($tagLen)
				{
					$this->output .= '>' . $tagText . '</' . $tagName . '>';
				}
				else
				{
					$this->output .= '/>';
				}
			}
			elseif ($tagLen)
			{
				$this->output .= '><s>' . $tagText . '</s>';
			}
			else
			{
				$this->output .= '>';
			}
		}
		else
		{
			if ($tagLen)
			{
				$this->output .= '<e>' . $tagText . '</e>';
			}

			$this->output .= '</' . $tagName . '>';
		}
		$this->pos = $tagPos + $tagLen;
		$this->wsPos = $this->pos;
		while ($skipAfter && $this->wsPos < $this->textLen && $this->text[$this->wsPos] === "\n")
		{
			--$skipAfter;
			++$this->wsPos;
		}
	}
	protected function outputText($catchupPos, $maxLines, $closeParagraph)
	{
		if ($closeParagraph)
		{
			if (!($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS))
			{
				$closeParagraph = false;
			}
			else
			{
				$maxLines = -1;
			}
		}

		if ($this->pos >= $catchupPos)
		{
			if ($closeParagraph)
			{
				$this->outputParagraphEnd();
			}

			return;
		}
		if ($this->wsPos > $this->pos)
		{
			$skipPos       = min($catchupPos, $this->wsPos);
			$this->output .= substr($this->text, $this->pos, $skipPos - $this->pos);
			$this->pos     = $skipPos;

			if ($this->pos >= $catchupPos)
			{
				if ($closeParagraph)
				{
					$this->outputParagraphEnd();
				}

				return;
			}
		}
		if ($this->context['flags'] & self::RULE_IGNORE_TEXT)
		{
			$catchupLen  = $catchupPos - $this->pos;
			$catchupText = substr($this->text, $this->pos, $catchupLen);
			if (strspn($catchupText, " \n\t") < $catchupLen)
			{
				$catchupText = '<i>' . htmlspecialchars($catchupText, ENT_NOQUOTES, 'UTF-8') . '</i>';
			}

			$this->output .= $catchupText;
			$this->pos = $catchupPos;

			if ($closeParagraph)
			{
				$this->outputParagraphEnd();
			}

			return;
		}
		$ignorePos = $catchupPos;
		$ignoreLen = 0;
		while ($maxLines && --$ignorePos >= $this->pos)
		{
			$c = $this->text[$ignorePos];
			if (strpos(self::WHITESPACE, $c) === false)
			{
				break;
			}

			if ($c === "\n")
			{
				--$maxLines;
			}

			++$ignoreLen;
		}
		$catchupPos -= $ignoreLen;
		if ($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS)
		{
			if (!$this->context['inParagraph'])
			{
				$this->outputWhitespace($catchupPos);

				if ($catchupPos > $this->pos)
				{
					$this->outputParagraphStart($catchupPos);
				}
			}
			$pbPos = strpos($this->text, "\n\n", $this->pos);

			while ($pbPos !== false && $pbPos < $catchupPos)
			{
				$this->outputText($pbPos, 0, true);
				$this->outputParagraphStart($catchupPos);

				$pbPos = strpos($this->text, "\n\n", $this->pos);
			}
		}
		if ($catchupPos > $this->pos)
		{
			$catchupText = htmlspecialchars(
				substr($this->text, $this->pos, $catchupPos - $this->pos),
				ENT_NOQUOTES,
				'UTF-8'
			);
			if (($this->context['flags'] & self::RULES_AUTO_LINEBREAKS) === self::RULE_ENABLE_AUTO_BR)
			{
				$catchupText = str_replace("\n", "<br/>\n", $catchupText);
			}

			$this->output .= $catchupText;
		}
		if ($closeParagraph)
		{
			$this->outputParagraphEnd();
		}
		if ($ignoreLen)
		{
			$this->output .= substr($this->text, $catchupPos, $ignoreLen);
		}
		$this->pos = $catchupPos + $ignoreLen;
	}
	protected function outputBrTag(Tag $tag)
	{
		$this->outputText($tag->getPos(), 0, false);
		$this->output .= '<br/>';
	}
	protected function outputIgnoreTag(Tag $tag)
	{
		$tagPos = $tag->getPos();
		$tagLen = $tag->getLen();
		$ignoreText = substr($this->text, $tagPos, $tagLen);
		$this->outputText($tagPos, 0, false);
		$this->output .= '<i>' . htmlspecialchars($ignoreText, ENT_NOQUOTES, 'UTF-8') . '</i>';
		$this->isRich = true;
		$this->pos = $tagPos + $tagLen;
	}
	protected function outputParagraphStart($maxPos)
	{
		if ($this->context['inParagraph']
		 || !($this->context['flags'] & self::RULE_CREATE_PARAGRAPHS))
		{
			return;
		}
		$this->outputWhitespace($maxPos);
		if ($this->pos < $this->textLen)
		{
			$this->output .= '<p>';
			$this->context['inParagraph'] = true;
		}
	}
	protected function outputParagraphEnd()
	{
		if (!$this->context['inParagraph'])
		{
			return;
		}

		$this->output .= '</p>';
		$this->context['inParagraph'] = false;
	}
	protected function outputVerbatim(Tag $tag)
	{
		$flags = $this->context['flags'];
		$this->context['flags'] = $tag->getFlags();
		$this->outputText($this->currentTag->getPos() + $this->currentTag->getLen(), 0, false);
		$this->context['flags'] = $flags;
	}
	protected function outputWhitespace($maxPos)
	{
		if ($maxPos > $this->pos)
		{
			$spn = strspn($this->text, self::WHITESPACE, $this->pos, $maxPos - $this->pos);

			if ($spn)
			{
				$this->output .= substr($this->text, $this->pos, $spn);
				$this->pos += $spn;
			}
		}
	}
	public function disablePlugin($pluginName)
	{
		if (isset($this->pluginsConfig[$pluginName]))
		{
			$pluginConfig = $this->pluginsConfig[$pluginName];
			unset($this->pluginsConfig[$pluginName]);
			$pluginConfig['isDisabled'] = true;
			$this->pluginsConfig[$pluginName] = $pluginConfig;
		}
	}
	public function enablePlugin($pluginName)
	{
		if (isset($this->pluginsConfig[$pluginName]))
		{
			$this->pluginsConfig[$pluginName]['isDisabled'] = false;
		}
	}
	protected function executePluginParser($pluginName)
	{
		$pluginConfig = $this->pluginsConfig[$pluginName];
		if (isset($pluginConfig['quickMatch']) && strpos($this->text, $pluginConfig['quickMatch']) === false)
		{
			return;
		}

		$matches = [];
		if (isset($pluginConfig['regexp'], $pluginConfig['regexpLimit']))
		{
			$matches = $this->getMatches($pluginConfig['regexp'], $pluginConfig['regexpLimit']);
			if (empty($matches))
			{
				return;
			}
		}
		call_user_func($this->getPluginParser($pluginName), $this->text, $matches);
	}
	protected function executePluginParsers()
	{
		foreach ($this->pluginsConfig as $pluginName => $pluginConfig)
		{
			if (empty($pluginConfig['isDisabled']))
			{
				$this->executePluginParser($pluginName);
			}
		}
	}
	protected function getMatches($regexp, $limit)
	{
		$cnt = preg_match_all($regexp, $this->text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
		if ($cnt > $limit)
		{
			$matches = array_slice($matches, 0, $limit);
		}

		return $matches;
	}
	protected function getPluginParser($pluginName)
	{
		if (!isset($this->pluginParsers[$pluginName]))
		{
			$pluginConfig = $this->pluginsConfig[$pluginName];
			$className = (isset($pluginConfig['className']))
			           ? $pluginConfig['className']
			           : 's9e\\TextFormatter\\Plugins\\' . $pluginName . '\\Parser';
			$this->pluginParsers[$pluginName] = [new $className($this, $pluginConfig), 'parse'];
		}

		return $this->pluginParsers[$pluginName];
	}
	public function registerParser($pluginName, $parser, $regexp = null, $limit = PHP_INT_MAX)
	{
		if (!is_callable($parser))
		{
			throw new InvalidArgumentException('Argument 1 passed to ' . __METHOD__ . ' must be a valid callback');
		}
		if (!isset($this->pluginsConfig[$pluginName]))
		{
			$this->pluginsConfig[$pluginName] = [];
		}
		if (isset($regexp))
		{
			$this->pluginsConfig[$pluginName]['regexp']      = $regexp;
			$this->pluginsConfig[$pluginName]['regexpLimit'] = $limit;
		}
		$this->pluginParsers[$pluginName] = $parser;
	}
	protected function closeAncestor(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['closeAncestor']))
			{
				$i = count($this->openTags);

				while (--$i >= 0)
				{
					$ancestor     = $this->openTags[$i];
					$ancestorName = $ancestor->getName();

					if (isset($tagConfig['rules']['closeAncestor'][$ancestorName]))
					{
						++$this->currentFixingCost;
						$this->tagStack[] = $tag;
						$this->addMagicEndTag($ancestor, $tag->getPos(), $tag->getSortPriority() - 1);

						return true;
					}
				}
			}
		}

		return false;
	}
	protected function closeParent(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['closeParent']))
			{
				$parent     = end($this->openTags);
				$parentName = $parent->getName();

				if (isset($tagConfig['rules']['closeParent'][$parentName]))
				{
					++$this->currentFixingCost;
					$this->tagStack[] = $tag;
					$this->addMagicEndTag($parent, $tag->getPos(), $tag->getSortPriority() - 1);

					return true;
				}
			}
		}

		return false;
	}
	protected function createChild(Tag $tag)
	{
		$tagConfig = $this->tagsConfig[$tag->getName()];
		if (isset($tagConfig['rules']['createChild']))
		{
			$priority = -1000;
			$tagPos   = $this->pos + strspn($this->text, " \n\r\t", $this->pos);
			foreach ($tagConfig['rules']['createChild'] as $tagName)
			{
				$this->addStartTag($tagName, $tagPos, 0, ++$priority);
			}
		}
	}
	protected function fosterParent(Tag $tag)
	{
		if (!empty($this->openTags))
		{
			$tagName   = $tag->getName();
			$tagConfig = $this->tagsConfig[$tagName];

			if (!empty($tagConfig['rules']['fosterParent']))
			{
				$parent     = end($this->openTags);
				$parentName = $parent->getName();

				if (isset($tagConfig['rules']['fosterParent'][$parentName]))
				{
					if ($parentName !== $tagName && $this->currentFixingCost < $this->maxFixingCost)
					{
						$this->addFosterTag($tag, $parent);
					}
					$this->tagStack[] = $tag;
					$this->addMagicEndTag($parent, $tag->getPos(), $tag->getSortPriority() - 1);
					$this->currentFixingCost += 4;

					return true;
				}
			}
		}

		return false;
	}
	protected function requireAncestor(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagConfig = $this->tagsConfig[$tagName];

		if (isset($tagConfig['rules']['requireAncestor']))
		{
			foreach ($tagConfig['rules']['requireAncestor'] as $ancestorName)
			{
				if (!empty($this->cntOpen[$ancestorName]))
				{
					return false;
				}
			}

			$this->logger->err('Tag requires an ancestor', [
				'requireAncestor' => implode(',', $tagConfig['rules']['requireAncestor']),
				'tag'             => $tag
			]);

			return true;
		}

		return false;
	}
	protected function addFosterTag(Tag $tag, Tag $fosterTag)
	{
		list($childPos, $childPrio) = $this->getMagicStartCoords($tag->getPos() + $tag->getLen());
		$childTag = $this->addCopyTag($fosterTag, $childPos, 0, $childPrio);
		$tag->cascadeInvalidationTo($childTag);
	}
	protected function addMagicEndTag(Tag $startTag, $tagPos, $prio = 0)
	{
		$tagName = $startTag->getName();
		if (($this->currentTag->getFlags() | $startTag->getFlags()) & self::RULE_IGNORE_WHITESPACE)
		{
			$tagPos = $this->getMagicEndPos($tagPos);
		}
		$endTag = $this->addEndTag($tagName, $tagPos, 0, $prio);
		$endTag->pairWith($startTag);

		return $endTag;
	}
	protected function getMagicEndPos($tagPos)
	{
		while ($tagPos > $this->pos && strpos(self::WHITESPACE, $this->text[$tagPos - 1]) !== false)
		{
			--$tagPos;
		}

		return $tagPos;
	}
	protected function getMagicStartCoords($tagPos)
	{
		if (empty($this->tagStack))
		{
			$nextPos  = $this->textLen + 1;
			$nextPrio = 0;
		}
		else
		{
			$nextTag  = end($this->tagStack);
			$nextPos  = $nextTag->getPos();
			$nextPrio = $nextTag->getSortPriority();
		}
		while ($tagPos < $nextPos && strpos(self::WHITESPACE, $this->text[$tagPos]) !== false)
		{
			++$tagPos;
		}
		$prio = ($tagPos === $nextPos) ? $nextPrio - 1 : 0;

		return [$tagPos, $prio];
	}
	protected function isFollowedByClosingTag(Tag $tag)
	{
		return (empty($this->tagStack)) ? false : end($this->tagStack)->canClose($tag);
	}
	protected function processTags()
	{
		if (empty($this->tagStack))
		{
			return;
		}
		foreach (array_keys($this->tagsConfig) as $tagName)
		{
			$this->cntOpen[$tagName]  = 0;
			$this->cntTotal[$tagName] = 0;
		}
		do
		{
			while (!empty($this->tagStack))
			{
				if (!$this->tagStackIsSorted)
				{
					$this->sortTags();
				}

				$this->currentTag = array_pop($this->tagStack);
				$this->processCurrentTag();
			}
			foreach ($this->openTags as $startTag)
			{
				$this->addMagicEndTag($startTag, $this->textLen);
			}
		}
		while (!empty($this->tagStack));
	}
	protected function processCurrentTag()
	{
		if (($this->context['flags'] & self::RULE_IGNORE_TAGS)
		 && !$this->currentTag->canClose(end($this->openTags))
		 && !$this->currentTag->isSystemTag())
		{
			$this->currentTag->invalidate();
		}

		$tagPos = $this->currentTag->getPos();
		$tagLen = $this->currentTag->getLen();
		if ($this->pos > $tagPos && !$this->currentTag->isInvalid())
		{
			$startTag = $this->currentTag->getStartTag();

			if ($startTag && in_array($startTag, $this->openTags, true))
			{
				$this->addEndTag(
					$startTag->getName(),
					$this->pos,
					max(0, $tagPos + $tagLen - $this->pos)
				)->pairWith($startTag);
				return;
			}
			if ($this->currentTag->isIgnoreTag())
			{
				$ignoreLen = $tagPos + $tagLen - $this->pos;

				if ($ignoreLen > 0)
				{
					$this->addIgnoreTag($this->pos, $ignoreLen);

					return;
				}
			}
			$this->currentTag->invalidate();
		}

		if ($this->currentTag->isInvalid())
		{
			return;
		}

		if ($this->currentTag->isIgnoreTag())
		{
			$this->outputIgnoreTag($this->currentTag);
		}
		elseif ($this->currentTag->isBrTag())
		{
			if (!($this->context['flags'] & self::RULE_PREVENT_BR))
			{
				$this->outputBrTag($this->currentTag);
			}
		}
		elseif ($this->currentTag->isParagraphBreak())
		{
			$this->outputText($this->currentTag->getPos(), 0, true);
		}
		elseif ($this->currentTag->isVerbatim())
		{
			$this->outputVerbatim($this->currentTag);
		}
		elseif ($this->currentTag->isStartTag())
		{
			$this->processStartTag($this->currentTag);
		}
		else
		{
			$this->processEndTag($this->currentTag);
		}
	}
	protected function processStartTag(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagConfig = $this->tagsConfig[$tagName];
		if ($this->cntTotal[$tagName] >= $tagConfig['tagLimit'])
		{
			$this->logger->err(
				'Tag limit exceeded',
				[
					'tag'      => $tag,
					'tagName'  => $tagName,
					'tagLimit' => $tagConfig['tagLimit']
				]
			);
			$tag->invalidate();

			return;
		}

		FilterProcessing::filterTag($tag, $this, $this->tagsConfig, $this->openTags);
		if ($tag->isInvalid())
		{
			return;
		}

		if ($this->currentFixingCost < $this->maxFixingCost)
		{
			if ($this->fosterParent($tag) || $this->closeParent($tag) || $this->closeAncestor($tag))
			{
				return;
			}
		}

		if ($this->cntOpen[$tagName] >= $tagConfig['nestingLimit'])
		{
			$this->logger->err(
				'Nesting limit exceeded',
				[
					'tag'          => $tag,
					'tagName'      => $tagName,
					'nestingLimit' => $tagConfig['nestingLimit']
				]
			);
			$tag->invalidate();

			return;
		}

		if (!$this->tagIsAllowed($tagName))
		{
			$msg     = 'Tag is not allowed in this context';
			$context = ['tag' => $tag, 'tagName' => $tagName];
			if ($tag->getLen() > 0)
			{
				$this->logger->warn($msg, $context);
			}
			else
			{
				$this->logger->debug($msg, $context);
			}
			$tag->invalidate();

			return;
		}

		if ($this->requireAncestor($tag))
		{
			$tag->invalidate();

			return;
		}
		if ($tag->getFlags() & self::RULE_AUTO_CLOSE
		 && !$tag->isSelfClosingTag()
		 && !$tag->getEndTag()
		 && !$this->isFollowedByClosingTag($tag))
		{
			$newTag = new Tag(Tag::SELF_CLOSING_TAG, $tagName, $tag->getPos(), $tag->getLen());
			$newTag->setAttributes($tag->getAttributes());
			$newTag->setFlags($tag->getFlags());

			$tag = $newTag;
		}

		if ($tag->getFlags() & self::RULE_TRIM_FIRST_LINE
		 && substr($this->text, $tag->getPos() + $tag->getLen(), 1) === "\n")
		{
			$this->addIgnoreTag($tag->getPos() + $tag->getLen(), 1);
		}
		$this->outputTag($tag);
		$this->pushContext($tag);
		$this->createChild($tag);
	}
	protected function processEndTag(Tag $tag)
	{
		$tagName = $tag->getName();

		if (empty($this->cntOpen[$tagName]))
		{
			return;
		}
		$closeTags = [];
		$i = count($this->openTags);
		while (--$i >= 0)
		{
			$openTag = $this->openTags[$i];

			if ($tag->canClose($openTag))
			{
				break;
			}

			$closeTags[] = $openTag;
			++$this->currentFixingCost;
		}

		if ($i < 0)
		{
			$this->logger->debug('Skipping end tag with no start tag', ['tag' => $tag]);

			return;
		}
		$flags = $tag->getFlags();
		foreach ($closeTags as $openTag)
		{
			$flags |= $openTag->getFlags();
		}
		$ignoreWhitespace = (bool) ($flags & self::RULE_IGNORE_WHITESPACE);
		$keepReopening = (bool) ($this->currentFixingCost < $this->maxFixingCost);
		$reopenTags = [];
		foreach ($closeTags as $openTag)
		{
			$openTagName = $openTag->getName();
			if ($keepReopening)
			{
				if ($openTag->getFlags() & self::RULE_AUTO_REOPEN)
				{
					$reopenTags[] = $openTag;
				}
				else
				{
					$keepReopening = false;
				}
			}
			$tagPos = $tag->getPos();
			if ($ignoreWhitespace)
			{
				$tagPos = $this->getMagicEndPos($tagPos);
			}
			$endTag = new Tag(Tag::END_TAG, $openTagName, $tagPos, 0);
			$endTag->setFlags($openTag->getFlags());
			$this->outputTag($endTag);
			$this->popContext();
		}
		$this->outputTag($tag);
		$this->popContext();
		if (!empty($closeTags) && $this->currentFixingCost < $this->maxFixingCost)
		{
			$ignorePos = $this->pos;

			$i = count($this->tagStack);
			while (--$i >= 0 && ++$this->currentFixingCost < $this->maxFixingCost)
			{
				$upcomingTag = $this->tagStack[$i];
				if ($upcomingTag->getPos() > $ignorePos
				 || $upcomingTag->isStartTag())
				{
					break;
				}
				$j = count($closeTags);

				while (--$j >= 0 && ++$this->currentFixingCost < $this->maxFixingCost)
				{
					if ($upcomingTag->canClose($closeTags[$j]))
					{
						array_splice($closeTags, $j, 1);

						if (isset($reopenTags[$j]))
						{
							array_splice($reopenTags, $j, 1);
						}
						$ignorePos = max(
							$ignorePos,
							$upcomingTag->getPos() + $upcomingTag->getLen()
						);

						break;
					}
				}
			}

			if ($ignorePos > $this->pos)
			{
				$this->outputIgnoreTag(new Tag(Tag::SELF_CLOSING_TAG, 'i', $this->pos, $ignorePos - $this->pos));
			}
		}
		foreach ($reopenTags as $startTag)
		{
			$newTag = $this->addCopyTag($startTag, $this->pos, 0);
			$endTag = $startTag->getEndTag();
			if ($endTag)
			{
				$newTag->pairWith($endTag);
			}
		}
	}
	protected function popContext()
	{
		$tag = array_pop($this->openTags);
		--$this->cntOpen[$tag->getName()];
		$this->context = $this->context['parentContext'];
	}
	protected function pushContext(Tag $tag)
	{
		$tagName   = $tag->getName();
		$tagFlags  = $tag->getFlags();
		$tagConfig = $this->tagsConfig[$tagName];

		++$this->cntTotal[$tagName];
		if ($tag->isSelfClosingTag())
		{
			return;
		}
		$allowed = [];
		foreach ($this->context['allowed'] as $k => $v)
		{
			if (!($tagFlags & self::RULE_IS_TRANSPARENT))
			{
				$v = ($v & 0xFF00) | ($v >> 8);
			}
			$allowed[] = $tagConfig['allowed'][$k] & $v;
		}
		$flags = $tagFlags | ($this->context['flags'] & self::RULES_INHERITANCE);
		if ($flags & self::RULE_DISABLE_AUTO_BR)
		{
			$flags &= ~self::RULE_ENABLE_AUTO_BR;
		}

		++$this->cntOpen[$tagName];
		$this->openTags[] = $tag;
		$this->context = [
			'allowed'       => $allowed,
			'flags'         => $flags,
			'inParagraph'   => false,
			'parentContext' => $this->context
		];
	}
	protected function tagIsAllowed($tagName)
	{
		$n = $this->tagsConfig[$tagName]['bitNumber'];

		return (bool) ($this->context['allowed'][$n >> 3] & (1 << ($n & 7)));
	}
	public function addStartTag($name, $pos, $len, $prio = 0)
	{
		return $this->addTag(Tag::START_TAG, $name, $pos, $len, $prio);
	}
	public function addEndTag($name, $pos, $len, $prio = 0)
	{
		return $this->addTag(Tag::END_TAG, $name, $pos, $len, $prio);
	}
	public function addSelfClosingTag($name, $pos, $len, $prio = 0)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, $name, $pos, $len, $prio);
	}
	public function addBrTag($pos, $prio = 0)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'br', $pos, 0, $prio);
	}
	public function addIgnoreTag($pos, $len, $prio = 0)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'i', $pos, min($len, $this->textLen - $pos), $prio);
	}
	public function addParagraphBreak($pos, $prio = 0)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'pb', $pos, 0, $prio);
	}
	public function addCopyTag(Tag $tag, $pos, $len, $prio = null)
	{
		if (!isset($prio))
		{
			$prio = $tag->getSortPriority();
		}
		$copy = $this->addTag($tag->getType(), $tag->getName(), $pos, $len, $prio);
		$copy->setAttributes($tag->getAttributes());

		return $copy;
	}
	protected function addTag($type, $name, $pos, $len, $prio)
	{
		$tag = new Tag($type, $name, $pos, $len, $prio);
		if (isset($this->tagsConfig[$name]))
		{
			$tag->setFlags($this->tagsConfig[$name]['rules']['flags']);
		}
		if ((!isset($this->tagsConfig[$name]) && !$tag->isSystemTag())
		 || $this->isInvalidTextSpan($pos, $len))
		{
			$tag->invalidate();
		}
		elseif (!empty($this->tagsConfig[$name]['isDisabled']))
		{
			$this->logger->warn(
				'Tag is disabled',
				[
					'tag'     => $tag,
					'tagName' => $name
				]
			);
			$tag->invalidate();
		}
		else
		{
			$this->insertTag($tag);
		}

		return $tag;
	}
	protected function isInvalidTextSpan($pos, $len)
	{
		return ($len < 0 || $pos < 0 || $pos + $len > $this->textLen || preg_match('([\\x80-\\xBF])', substr($this->text, $pos, 1) . substr($this->text, $pos + $len, 1)));
	}
	protected function insertTag(Tag $tag)
	{
		if (!$this->tagStackIsSorted)
		{
			$this->tagStack[] = $tag;
		}
		else
		{
			$i   = count($this->tagStack);
			$key = $this->getSortKey($tag);
			while ($i > 0 && $key > $this->getSortKey($this->tagStack[$i - 1]))
			{
				$this->tagStack[$i] = $this->tagStack[$i - 1];
				--$i;
			}
			$this->tagStack[$i] = $tag;
		}
	}
	public function addTagPair($name, $startPos, $startLen, $endPos, $endLen, $prio = 0)
	{
		$endTag   = $this->addEndTag($name, $endPos, $endLen, -$prio);
		$startTag = $this->addStartTag($name, $startPos, $startLen, $prio);
		$startTag->pairWith($endTag);

		return $startTag;
	}
	public function addVerbatim($pos, $len, $prio = 0)
	{
		return $this->addTag(Tag::SELF_CLOSING_TAG, 'v', $pos, $len, $prio);
	}
	protected function sortTags()
	{
		$arr = [];
		foreach ($this->tagStack as $i => $tag)
		{
			$key       = $this->getSortKey($tag, $i);
			$arr[$key] = $tag;
		}
		krsort($arr);

		$this->tagStack         = array_values($arr);
		$this->tagStackIsSorted = true;
	}
	protected function getSortKey(Tag $tag, int $tagIndex = 0): string
	{
		$prioFlag = ($tag->getSortPriority() >= 0);
		$prio     = $tag->getSortPriority();
		if (!$prioFlag)
		{
			$prio += (1 << 30);
		}
		$lenFlag = ($tag->getLen() > 0);
		if ($lenFlag)
		{
			$lenOrder = $this->textLen - $tag->getLen();
		}
		else
		{
			$order = [
				Tag::END_TAG          => 0,
				Tag::SELF_CLOSING_TAG => 1,
				Tag::START_TAG        => 2
			];
			$lenOrder = $order[$tag->getType()];
		}

		return sprintf('%8x%d%8x%d%8x%8x', $tag->getPos(), $prioFlag, $prio, $lenFlag, $lenOrder, $tagIndex);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class EmailFilter
{
	public static function filter($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_EMAIL);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class FalseFilter
{
	public static function filter($attrValue)
	{
		return false;
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class HashmapFilter
{
	public static function filter($attrValue, array $map, $strict)
	{
		if (isset($map[$attrValue]))
		{
			return $map[$attrValue];
		}

		return ($strict) ? false : $attrValue;
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class MapFilter
{
	public static function filter($attrValue, array $map)
	{
		foreach ($map as $pair)
		{
			if (preg_match($pair[0], $attrValue))
			{
				return $pair[1];
			}
		}

		return $attrValue;
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class NetworkFilter
{
	public static function filterIp($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_IP);
	}
	public static function filterIpport($attrValue)
	{
		if (preg_match('/^\\[([^\\]]+)(\\]:[1-9][0-9]*)$/D', $attrValue, $m))
		{
			$ip = self::filterIpv6($m[1]);

			if ($ip === false)
			{
				return false;
			}

			return '[' . $ip . $m[2];
		}

		if (preg_match('/^([^:]+)(:[1-9][0-9]*)$/D', $attrValue, $m))
		{
			$ip = self::filterIpv4($m[1]);

			if ($ip === false)
			{
				return false;
			}

			return $ip . $m[2];
		}

		return false;
	}
	public static function filterIpv4($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4);
	}
	public static function filterIpv6($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

use s9e\TextFormatter\Parser\Logger;

class NumericFilter
{
	public static function filterFloat($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_FLOAT);
	}
	public static function filterInt($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_INT);
	}
	public static function filterRange($attrValue, $min, $max, Logger $logger = null)
	{
		$attrValue = filter_var($attrValue, FILTER_VALIDATE_INT);

		if ($attrValue === false)
		{
			return false;
		}

		if ($attrValue < $min)
		{
			if (isset($logger))
			{
				$logger->warn(
					'Value outside of range, adjusted up to min value',
					[
						'attrValue' => $attrValue,
						'min'       => $min,
						'max'       => $max
					]
				);
			}

			return $min;
		}

		if ($attrValue > $max)
		{
			if (isset($logger))
			{
				$logger->warn(
					'Value outside of range, adjusted down to max value',
					[
						'attrValue' => $attrValue,
						'min'       => $min,
						'max'       => $max
					]
				);
			}

			return $max;
		}

		return $attrValue;
	}
	public static function filterUint($attrValue)
	{
		return filter_var($attrValue, FILTER_VALIDATE_INT, [
			'options' => ['min_range' => 0]
		]);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class RegexpFilter
{
	public static function filter($attrValue, $regexp)
	{
		return filter_var($attrValue, FILTER_VALIDATE_REGEXP, [
			'options' => ['regexp' => $regexp]
		]);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

class TimestampFilter
{
	public static function filter($attrValue)
	{
		if (preg_match('/^(?=\\d)(?:(\\d+)h)?(?:(\\d+)m)?(?:(\\d+)s)?$/D', $attrValue, $m))
		{
			$m += [0, 0, 0, 0];

			return intval($m[1]) * 3600 + intval($m[2]) * 60 + intval($m[3]);
		}

		return NumericFilter::filterUint($attrValue);
	}
}
namespace s9e\TextFormatter\Parser\AttributeFilters;

use s9e\TextFormatter\Parser\Logger;

class UrlFilter
{
	public static function filter($attrValue, array $urlConfig, Logger $logger = null)
	{
		$p = self::parseUrl(trim($attrValue));

		$error = self::validateUrl($urlConfig, $p);
		if (!empty($error))
		{
			if (isset($logger))
			{
				$p['attrValue'] = $attrValue;
				$logger->err($error, $p);
			}

			return false;
		}

		return self::rebuildUrl($p);
	}
	protected static function parseUrl($url)
	{
		$regexp = '(^(?:([a-z][-+.\\w]*):)?(?://(?:([^:/?#]*)(?::([^/?#]*)?)?@)?(?:(\\[[a-f\\d:]+\\]|[^:/?#]+)(?::(\\d*))?)?(?![^/?#]))?([^?#]*)(\\?[^#]*)?(#.*)?$)Di';
		preg_match($regexp, $url, $m);

		$parts  = [];
		$tokens = ['scheme', 'user', 'pass', 'host', 'port', 'path', 'query', 'fragment'];
		foreach ($tokens as $i => $name)
		{
			$parts[$name] = (isset($m[$i + 1])) ? $m[$i + 1] : '';
		}
		$parts['scheme'] = strtolower($parts['scheme']);
		$parts['host'] = rtrim(preg_replace("/\xE3\x80\x82|\xEF(?:\xBC\x8E|\xBD\xA1)/s", '.', $parts['host']), '.');
		if (preg_match('#[^[:ascii:]]#', $parts['host']) && function_exists('idn_to_ascii'))
		{
			$variant = (defined('INTL_IDNA_VARIANT_UTS46')) ? INTL_IDNA_VARIANT_UTS46 : 0;
			$parts['host'] = idn_to_ascii($parts['host'], 0, $variant);
		}

		return $parts;
	}
	protected static function rebuildUrl(array $p)
	{
		$url = '';
		if ($p['scheme'] !== '')
		{
			$url .= $p['scheme'] . ':';
		}
		if ($p['host'] !== '')
		{
			$url .= '//';
			if ($p['user'] !== '')
			{
				$url .= rawurlencode(urldecode($p['user']));

				if ($p['pass'] !== '')
				{
					$url .= ':' . rawurlencode(urldecode($p['pass']));
				}

				$url .= '@';
			}

			$url .= $p['host'];
			if ($p['port'] !== '')
			{
				$url .= ':' . $p['port'];
			}
		}
		elseif ($p['scheme'] === 'file')
		{
			$url .= '//';
		}
		$path = $p['path'] . $p['query'] . $p['fragment'];
		$path = preg_replace_callback(
			'/%.?[a-f]/',
			function ($m)
			{
				return strtoupper($m[0]);
			},
			$path
		);
		$url .= self::sanitizeUrl($path);
		if (!$p['scheme'])
		{
			$url = preg_replace('#^([^/]*):#', '$1%3A', $url);
		}

		return $url;
	}
	public static function sanitizeUrl($url)
	{
		return preg_replace_callback(
			'/%(?![0-9A-Fa-f]{2})|[^!#-&*-;=?-Z_a-z]/S',
			function ($m)
			{
				return rawurlencode($m[0]);
			},
			$url
		);
	}
	protected static function validateUrl(array $urlConfig, array $p)
	{
		if ($p['scheme'] !== '' && !preg_match($urlConfig['allowedSchemes'], $p['scheme']))
		{
			return 'URL scheme is not allowed';
		}

		if ($p['host'] !== '')
		{
			$regexp = '/^(?!-)[-a-z0-9]{0,62}[a-z0-9](?:\\.(?!-)[-a-z0-9]{0,62}[a-z0-9])*$/i';
			if (!preg_match($regexp, $p['host']))
			{
				if (!NetworkFilter::filterIpv4($p['host'])
				 && !NetworkFilter::filterIpv6(preg_replace('/^\\[(.*)\\]$/', '$1', $p['host'])))
				{
					return 'URL host is invalid';
				}
			}

			if ((isset($urlConfig['disallowedHosts']) && preg_match($urlConfig['disallowedHosts'], $p['host']))
			 || (isset($urlConfig['restrictedHosts']) && !preg_match($urlConfig['restrictedHosts'], $p['host'])))
			{
				return 'URL host is not allowed';
			}
		}
		elseif (preg_match('(^(?:(?:f|ht)tps?)$)', $p['scheme']))
		{
			return 'Missing host';
		}
	}
}
namespace s9e\TextFormatter\Parser;

use s9e\TextFormatter\Parser;

class FilterProcessing
{
	public static function executeAttributePreprocessors(Tag $tag, array $tagConfig)
	{
		if (empty($tagConfig['attributePreprocessors']))
		{
			return;
		}

		foreach ($tagConfig['attributePreprocessors'] as list($attrName, $regexp, $map))
		{
			if ($tag->hasAttribute($attrName))
			{
				self::executeAttributePreprocessor($tag, $attrName, $regexp, $map);
			}
		}
	}
	public static function filterAttributes(Tag $tag, array $tagConfig, array $registeredVars, Logger $logger)
	{
		$attributes = [];
		foreach ($tagConfig['attributes'] as $attrName => $attrConfig)
		{
			$attrValue = false;
			if ($tag->hasAttribute($attrName))
			{
				$vars = [
					'attrName'       => $attrName,
					'attrValue'      => $tag->getAttribute($attrName),
					'logger'         => $logger,
					'registeredVars' => $registeredVars
				];
				$attrValue = self::executeAttributeFilterChain($attrConfig['filterChain'], $vars);
			}

			if ($attrValue !== false)
			{
				$attributes[$attrName] = $attrValue;
			}
			elseif (isset($attrConfig['defaultValue']))
			{
				$attributes[$attrName] = $attrConfig['defaultValue'];
			}
			elseif (!empty($attrConfig['required']))
			{
				$tag->invalidate();
			}
		}
		$tag->setAttributes($attributes);
	}
	public static function filterTag(Tag $tag, Parser $parser, array $tagsConfig, array $openTags)
	{
		$tagName   = $tag->getName();
		$tagConfig = $tagsConfig[$tagName];
		$logger = $parser->getLogger();
		$logger->setTag($tag);
		$text = $parser->getText();
		$vars = [
			'innerText'      => '',
			'logger'         => $logger,
			'openTags'       => $openTags,
			'outerText'      => substr($text, $tag->getPos(), $tag->getLen()),
			'parser'         => $parser,
			'registeredVars' => $parser->registeredVars,
			'tag'            => $tag,
			'tagConfig'      => $tagConfig,
			'tagText'        => substr($text, $tag->getPos(), $tag->getLen()),
			'text'           => $text
		];
		$endTag = $tag->getEndTag();
		if ($endTag)
		{
			$vars['innerText'] = substr($text, $tag->getPos() + $tag->getLen(), $endTag->getPos() - $tag->getPos() - $tag->getLen());
			$vars['outerText'] = substr($text, $tag->getPos(), $endTag->getPos() + $endTag->getLen() - $tag->getPos());
		}
		foreach ($tagConfig['filterChain'] as $filter)
		{
			if ($tag->isInvalid())
			{
				break;
			}
			self::executeFilter($filter, $vars);
		}
		$logger->unsetTag();
	}
	protected static function executeAttributeFilterChain(array $filterChain, array $vars)
	{
		$vars['logger']->setAttribute($vars['attrName']);
		foreach ($filterChain as $filter)
		{
			$vars['attrValue'] = self::executeFilter($filter, $vars);
			if ($vars['attrValue'] === false)
			{
				break;
			}
		}
		$vars['logger']->unsetAttribute();

		return $vars['attrValue'];
	}
	protected static function executeAttributePreprocessor(Tag $tag, $attrName, $regexp, $map)
	{
		$attrValue = $tag->getAttribute($attrName);
		$captures  = self::getNamedCaptures($attrValue, $regexp, $map);
		foreach ($captures as $k => $v)
		{
			if ($k === $attrName || !$tag->hasAttribute($k))
			{
				$tag->setAttribute($k, $v);
			}
		}
	}
	protected static function executeFilter(array $filter, array $vars)
	{
		$vars += ['registeredVars' => []];
		$vars += $vars['registeredVars'];
		$args = [];
		if (isset($filter['params']))
		{
			foreach ($filter['params'] as $k => $v)
			{
				$args[] = (isset($vars[$k])) ? $vars[$k] : $v;
			}
		}

		return call_user_func_array($filter['callback'], $args);
	}
	protected static function getNamedCaptures($str, $regexp, $map)
	{
		if (!preg_match($regexp, $str, $m))
		{
			return [];
		}

		$values = [];
		foreach ($map as $i => $k)
		{
			if (isset($m[$i]) && $m[$i] !== '')
			{
				$values[$k] = $m[$i];
			}
		}

		return $values;
	}
}
namespace s9e\TextFormatter\Parser;

use InvalidArgumentException;
use s9e\TextFormatter\Parser;

class Logger
{
	protected $attrName;
	protected $logs = [];
	protected $tag;
	protected function add($type, $msg, array $context)
	{
		if (!isset($context['attrName']) && isset($this->attrName))
		{
			$context['attrName'] = $this->attrName;
		}

		if (!isset($context['tag']) && isset($this->tag))
		{
			$context['tag'] = $this->tag;
		}

		$this->logs[] = [$type, $msg, $context];
	}
	public function clear()
	{
		$this->logs = [];
		$this->unsetAttribute();
		$this->unsetTag();
	}
	public function getLogs()
	{
		return $this->logs;
	}
	public function setAttribute($attrName)
	{
		$this->attrName = $attrName;
	}
	public function setTag(Tag $tag)
	{
		$this->tag = $tag;
	}
	public function unsetAttribute()
	{
		unset($this->attrName);
	}
	public function unsetTag()
	{
		unset($this->tag);
	}
	public function debug($msg, array $context = [])
	{
		$this->add('debug', $msg, $context);
	}
	public function err($msg, array $context = [])
	{
		$this->add('err', $msg, $context);
	}
	public function info($msg, array $context = [])
	{
		$this->add('info', $msg, $context);
	}
	public function warn($msg, array $context = [])
	{
		$this->add('warn', $msg, $context);
	}
}
namespace s9e\TextFormatter\Parser;

class Tag
{
	const START_TAG = 1;
	const END_TAG = 2;
	const SELF_CLOSING_TAG = self::START_TAG | self::END_TAG;
	protected $attributes = [];
	protected $cascade = [];
	protected $endTag = null;
	protected $flags = 0;
	protected $invalid = false;
	protected $len;
	protected $name;
	protected $pos;
	protected $sortPriority;
	protected $startTag = null;
	protected $type;
	public function __construct($type, $name, $pos, $len, $priority = 0)
	{
		$this->type = (int) $type;
		$this->name = $name;
		$this->pos  = (int) $pos;
		$this->len  = (int) $len;
		$this->sortPriority = (int) $priority;
	}
	public function addFlags($flags)
	{
		$this->flags |= $flags;
	}
	public function cascadeInvalidationTo(Tag $tag)
	{
		$this->cascade[] = $tag;
		if ($this->invalid)
		{
			$tag->invalidate();
		}
	}
	public function invalidate()
	{
		if (!$this->invalid)
		{
			$this->invalid = true;
			foreach ($this->cascade as $tag)
			{
				$tag->invalidate();
			}
		}
	}
	public function pairWith(Tag $tag)
	{
		if ($this->canBePaired($this, $tag))
		{
			$this->endTag  = $tag;
			$tag->startTag = $this;

			$this->cascadeInvalidationTo($tag);
		}
		elseif ($this->canBePaired($tag, $this))
		{
			$this->startTag = $tag;
			$tag->endTag    = $this;
		}
	}
	protected function canBePaired(Tag $startTag, Tag $endTag): bool
	{
		return $startTag->name === $endTag->name && $startTag->type === self::START_TAG && $endTag->type === self::END_TAG && $startTag->pos <= $endTag->pos;
	}
	public function removeFlags($flags)
	{
		$this->flags &= ~$flags;
	}
	public function setFlags($flags)
	{
		$this->flags = $flags;
	}
	public function getAttributes()
	{
		return $this->attributes;
	}
	public function getEndTag()
	{
		return $this->endTag;
	}
	public function getFlags()
	{
		return $this->flags;
	}
	public function getLen()
	{
		return $this->len;
	}
	public function getName()
	{
		return $this->name;
	}
	public function getPos()
	{
		return $this->pos;
	}
	public function getSortPriority()
	{
		return $this->sortPriority;
	}
	public function getStartTag()
	{
		return $this->startTag;
	}
	public function getType()
	{
		return $this->type;
	}
	public function canClose(Tag $startTag)
	{
		if ($this->invalid
		 || !$this->canBePaired($startTag, $this)
		 || ($this->startTag && $this->startTag !== $startTag)
		 || ($startTag->endTag && $startTag->endTag !== $this))
		{
			return false;
		}

		return true;
	}
	public function isBrTag()
	{
		return ($this->name === 'br');
	}
	public function isEndTag()
	{
		return (bool) ($this->type & self::END_TAG);
	}
	public function isIgnoreTag()
	{
		return ($this->name === 'i');
	}
	public function isInvalid()
	{
		return $this->invalid;
	}
	public function isParagraphBreak()
	{
		return ($this->name === 'pb');
	}
	public function isSelfClosingTag()
	{
		return ($this->type === self::SELF_CLOSING_TAG);
	}
	public function isSystemTag()
	{
		return (strpos('br i pb v', $this->name) !== false);
	}
	public function isStartTag()
	{
		return (bool) ($this->type & self::START_TAG);
	}
	public function isVerbatim()
	{
		return ($this->name === 'v');
	}
	public function getAttribute($attrName)
	{
		return $this->attributes[$attrName];
	}
	public function hasAttribute($attrName)
	{
		return isset($this->attributes[$attrName]);
	}
	public function removeAttribute($attrName)
	{
		unset($this->attributes[$attrName]);
	}
	public function setAttribute($attrName, $attrValue)
	{
		$this->attributes[$attrName] = $attrValue;
	}
	public function setAttributes(array $attributes)
	{
		$this->attributes = $attributes;
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser;

use s9e\TextFormatter\Parser\Tag;

trait LinkAttributesSetter
{
	protected function setLinkAttributes(Tag $tag, $linkInfo, $attrName)
	{
		$url   = trim($linkInfo);
		$title = '';
		$pos   = strpos($url, ' ');
		if ($pos !== false)
		{
			$title = substr(trim(substr($url, $pos)), 1, -1);
			$url   = substr($url, 0, $pos);
		}
		if (preg_match('/^<.+>$/', $url))
		{
			$url = str_replace('\\>', '>', substr($url, 1, -1));
		}

		$tag->setAttribute($attrName, $this->text->decode($url));
		if ($title > '')
		{
			$tag->setAttribute('title', $this->text->decode($title));
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser;

class ParsedText
{
	public $decodeHtmlEntities = false;
	protected $hasEscapedChars = false;
	public $hasReferences = false;
	public $linkReferences = [];
	protected $text;
	public function __construct($text)
	{
		if (strpos($text, '\\') !== false && preg_match('/\\\\[!"\'()*<>[\\\\\\]^_`~]/', $text))
		{
			$this->hasEscapedChars = true;
			$text = strtr(
				$text,
				[
					'\\!' => "\x1B0", '\\"'  => "\x1B1", "\\'" => "\x1B2", '\\(' => "\x1B3",
					'\\)' => "\x1B4", '\\*'  => "\x1B5", '\\<' => "\x1B6", '\\>' => "\x1B7",
					'\\[' => "\x1B8", '\\\\' => "\x1B9", '\\]' => "\x1BA", '\\^' => "\x1BB",
					'\\_' => "\x1BC", '\\`'  => "\x1BD", '\\~' => "\x1BE"
				]
			);
		}
		$this->text = $text . "\n\n\x17";
	}
	public function __toString()
	{
		return $this->text;
	}
	public function charAt($pos)
	{
		return $this->text[$pos];
	}
	public function decode($str)
	{
		if ($this->decodeHtmlEntities && strpos($str, '&') !== false)
		{
			$str = html_entity_decode($str, ENT_QUOTES, 'UTF-8');
		}
		$str = str_replace("\x1A", '', $str);

		if ($this->hasEscapedChars)
		{
			$str = strtr(
				$str,
				[
					"\x1B0" => '!', "\x1B1" => '"',  "\x1B2" => "'", "\x1B3" => '(',
					"\x1B4" => ')', "\x1B5" => '*',  "\x1B6" => '<', "\x1B7" => '>',
					"\x1B8" => '[', "\x1B9" => '\\', "\x1BA" => ']', "\x1BB" => '^',
					"\x1BC" => '_', "\x1BD" => '`',  "\x1BE" => '~'
				]
			);
		}

		return $str;
	}
	public function indexOf($str, $pos = 0)
	{
		return strpos($this->text, $str, $pos);
	}
	public function isAfterWhitespace($pos)
	{
		return ($pos > 0 && $this->isWhitespace($this->text[$pos - 1]));
	}
	public function isAlnum($chr)
	{
		return (strpos(' abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789', $chr) > 0);
	}
	public function isBeforeWhitespace($pos)
	{
		return $this->isWhitespace($this->text[$pos + 1]);
	}
	public function isSurroundedByAlnum($pos, $len)
	{
		return ($pos > 0 && $this->isAlnum($this->text[$pos - 1]) && $this->isAlnum($this->text[$pos + $len]));
	}
	public function isWhitespace($chr)
	{
		return (strpos(" \n\t", $chr) !== false);
	}
	public function markBoundary($pos)
	{
		$this->text[$pos] = "\x17";
	}
	public function overwrite($pos, $len)
	{
		if ($len > 0)
		{
			$this->text = substr($this->text, 0, $pos) . str_repeat("\x1A", $len) . substr($this->text, $pos + $len);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

use s9e\TextFormatter\Parser;
use s9e\TextFormatter\Plugins\Litedown\Parser\ParsedText;

abstract class AbstractPass
{
	protected $parser;
	protected $text;
	public function __construct(Parser $parser, ParsedText $text)
	{
		$this->parser = $parser;
		$this->text   = $text;
	}
	abstract public function parse();
}
namespace s9e\TextFormatter\Plugins;

use s9e\TextFormatter\Parser;

abstract class ParserBase
{
	protected $config;
	protected $parser;
	final public function __construct(Parser $parser, array $config)
	{
		$this->parser = $parser;
		$this->config = $config;

		$this->setUp();
	}
	protected function setUp()
	{
	}
	abstract public function parse($text, array $matches);
}
namespace s9e\TextFormatter\Plugins\TaskLists;

use s9e\TextFormatter\Parser;
use s9e\TextFormatter\Parser\Tag;

class Helper
{
	public static function filterListItem(Parser $parser, Tag $listItem, string $text): void
	{
		$pos  = $listItem->getPos() + $listItem->getLen();
		$pos += strspn($text, ' ', $pos);
		$str  = substr($text, $pos, 3);
		if (!preg_match('/\\[[ Xx]\\]/', $str))
		{
			return;
		}
		$taskId    = uniqid();
		$taskState = ($str === '[ ]') ? 'unchecked' : 'checked';

		$task = $parser->addSelfClosingTag('TASK', $pos, 3);
		$task->setAttribute('id',    $taskId);
		$task->setAttribute('state', $taskState);

		$listItem->cascadeInvalidationTo($task);
	}
	public static function getStats(string $xml): array
	{
		$stats = ['checked' => 0, 'unchecked' => 0];

		preg_match_all('((?<=<)TASK(?: [^=]++="[^"]*+")*? state="\\K\\w++)', $xml, $m);
		foreach ($m[0] as $state)
		{
			if (!isset($stats[$state]))
			{
				$stats[$state] = 0;
			}
			++$stats[$state];
		}

		return $stats;
	}
	public static function checkTask(string $xml, string $id): string
	{
		return self::setTaskState($xml, $id, 'checked', 'x');
	}
	public static function uncheckTask(string $xml, string $id): string
	{
		return self::setTaskState($xml, $id, 'unchecked', ' ');
	}
	protected static function setTaskState(string $xml, string $id, string $state, string $marker): string
	{
		return preg_replace_callback(
			'((?<=<)TASK(?: [^=]++="[^"]*+")*? id="' . preg_quote($id) . '"\\K([^>]*+)>[^<]*+(?=</TASK>))',
			function ($m) use ($state, $marker)
			{
				preg_match_all('( ([^=]++)="[^"]*+")', $m[1], $m);

				$attributes          = array_combine($m[1], $m[0]);
				$attributes['state'] = ' state="' . $state . '"';
				ksort($attributes);

				return implode('', $attributes) . '>[' . $marker . ']';
			},
			$xml
		);
	}
}
namespace s9e\TextFormatter;

use DOMDocument;
use InvalidArgumentException;

abstract class Renderer
{
	protected $params = [];
	protected $savedLocale = '0';
	protected function loadXML($xml)
	{
		$this->checkUnsupported($xml);
		$flags = (LIBXML_VERSION >= 20700) ? LIBXML_COMPACT | LIBXML_PARSEHUGE : 0;

		$useErrors = libxml_use_internal_errors(true);
		$dom       = new DOMDocument;
		$success   = $dom->loadXML($xml, $flags);
		libxml_use_internal_errors($useErrors);

		if (!$success)
		{
			throw new InvalidArgumentException('Cannot load XML: ' . libxml_get_last_error()->message);
		}

		return $dom;
	}
	public function render($xml)
	{
		if (substr($xml, 0, 3) === '<t>' && substr($xml, -4) === '</t>')
		{
			return $this->renderPlainText($xml);
		}
		else
		{
			return $this->renderRichText(preg_replace('(<[eis]>[^<]*</[eis]>)', '', $xml));
		}
	}
	protected function renderPlainText($xml)
	{
		$html = substr($xml, 3, -4);
		$html = str_replace('<br/>', '<br>', $html);
		$html = $this->decodeSMP($html);

		return $html;
	}
	abstract protected function renderRichText($xml);
	public function getParameter($paramName)
	{
		return (isset($this->params[$paramName])) ? $this->params[$paramName] : '';
	}
	public function getParameters()
	{
		return $this->params;
	}
	public function setParameter($paramName, $paramValue)
	{
		$this->params[$paramName] = (string) $paramValue;
	}
	public function setParameters(array $params)
	{
		foreach ($params as $paramName => $paramValue)
		{
			$this->setParameter($paramName, $paramValue);
		}
	}
	protected function checkUnsupported($xml)
	{
		if (preg_match('((?<=<)[!?])', $xml, $m))
		{
			$errors = [
				'!' => 'DTDs, CDATA nodes and comments are not allowed',
				'?' => 'Processing instructions are not allowed'
			];

			throw new InvalidArgumentException($errors[$m[0]]);
		}
	}
	protected function decodeSMP($str)
	{
		if (strpos($str, '&#') === false)
		{
			return $str;
		}

		return preg_replace_callback('(&#(?:x[0-9A-Fa-f]+|[0-9]+);)', __CLASS__ . '::decodeEntity', $str);
	}
	protected static function decodeEntity(array $m)
	{
		return htmlspecialchars(html_entity_decode($m[0], ENT_QUOTES, 'UTF-8'), ENT_COMPAT);
	}
	protected function restoreLocale(): void
	{
		if ($this->savedLocale !== 'C')
		{
			setlocale(LC_NUMERIC, $this->savedLocale);
		}
	}
	protected function setLocale(): void
	{
		$this->savedLocale = setlocale(LC_NUMERIC, '0');
		if ($this->savedLocale !== 'C')
		{
			setlocale(LC_NUMERIC, 'C');
		}
	}
}
namespace s9e\TextFormatter;

use DOMDocument;
use DOMXPath;

abstract class Utils
{
	public static function getAttributeValues($xml, $tagName, $attrName)
	{
		$values = [];
		if (strpos($xml, $tagName) !== false)
		{
			$regexp = '((?<=<)' . preg_quote($tagName) . '(?= )[^>]*? ' . preg_quote($attrName) . '="\\K[^"]*+)';
			preg_match_all($regexp, $xml, $matches);
			foreach ($matches[0] as $value)
			{
				$values[] = html_entity_decode($value, ENT_QUOTES, 'UTF-8');
			}
		}

		return $values;
	}
	public static function encodeUnicodeSupplementaryCharacters($str)
	{
		return preg_replace_callback(
			'([\\xF0-\\xF4]...)S',
			__CLASS__ . '::encodeUnicodeSupplementaryCharactersCallback',
			$str
		);
	}
	public static function removeFormatting($xml)
	{
		$dom   = self::loadXML($xml);
		$xpath = new DOMXPath($dom);
		foreach ($xpath->query('//e | //s') as $node)
		{
			$node->parentNode->removeChild($node);
		}

		return $dom->documentElement->textContent;
	}
	public static function removeTag($xml, $tagName, $nestingLevel = 0)
	{
		if (strpos($xml, $tagName) === false)
		{
			return $xml;
		}

		$dom   = self::loadXML($xml);
		$xpath = new DOMXPath($dom);
		$query = '//' . $tagName . '[count(ancestor::' . $tagName . ') >= ' . $nestingLevel . ']';
		$nodes = $xpath->query($query);
		foreach ($nodes as $node)
		{
			$node->parentNode->removeChild($node);
		}

		return self::saveXML($dom);
	}
	public static function replaceAttributes($xml, $tagName, callable $callback)
	{
		if (strpos($xml, $tagName) === false)
		{
			return $xml;
		}

		return preg_replace_callback(
			'((?<=<)' . preg_quote($tagName) . '(?=[ />])\\K[^>]*+)',
			function ($m) use ($callback)
			{
				$str = self::serializeAttributes($callback(self::parseAttributes($m[0])));
				if (substr($m[0], -1) === '/')
				{
					$str .= '/';
				}

				return $str;
			},
			$xml
		);
	}
	protected static function encodeUnicodeSupplementaryCharactersCallback(array $m)
	{
		$utf8 = $m[0];
		$cp   = (ord($utf8[0]) << 18) + (ord($utf8[1]) << 12) + (ord($utf8[2]) << 6) + ord($utf8[3]) - 0x3C82080;

		return '&#' . $cp . ';';
	}
	protected static function loadXML($xml)
	{
		$flags = (LIBXML_VERSION >= 20700) ? LIBXML_COMPACT | LIBXML_PARSEHUGE : 0;

		$dom = new DOMDocument;
		$dom->loadXML($xml, $flags);

		return $dom;
	}
	protected static function parseAttributes($xml)
	{
		$attributes = [];
		if (strpos($xml, '="') !== false)
		{
			preg_match_all('(([^ =]++)="([^"]*))', $xml, $matches);
			foreach ($matches[1] as $i => $attrName)
			{
				$attributes[$attrName] = html_entity_decode($matches[2][$i], ENT_QUOTES, 'UTF-8');
			}
		}

		return $attributes;
	}
	protected static function saveXML(DOMDocument $dom)
	{
		return self::encodeUnicodeSupplementaryCharacters($dom->saveXML($dom->documentElement));
	}
	protected static function serializeAttributes(array $attributes)
	{
		$xml = '';
		ksort($attributes);
		foreach ($attributes as $attrName => $attrValue)
		{
			$xml .= ' ' . htmlspecialchars($attrName, ENT_QUOTES) . '="' . htmlspecialchars($attrValue, ENT_COMPAT) . '"';
		}
		$xml = preg_replace('/\\r\\n?/', "\n", $xml);
		$xml = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/S', '', $xml);
		$xml = str_replace("\n", '&#10;', $xml);

		return self::encodeUnicodeSupplementaryCharacters($xml);
	}
}
namespace s9e\TextFormatter\Bundles;

abstract class Fatdown extends \s9e\TextFormatter\Bundle
{
	protected static $parser;
	protected static $renderer;
	public static function getJS()
	{
		return '(function(){function ba(a){var b=0;return function(){return b<a.length?{done:!1,value:a[b++]}:{done:!0}}}
var ca=[""],da=[0,0,0],fa=["","t"],ha=["","id"],ia={flags:0},na=["","type"],oa={flags:514},pa={flags:3089},qa={flags:3201},ra=["","album_id"],ua=["","track_id"],va=[32960,257,256],wa=[32896,257,257],xa=[65519,65329,257],ya=[65477,65281,257],za=[39819,65329,257],Aa=[65519,65313,257],Ba=[65424,65280,257],Ca=[65408,65288,257],Da=[63463,65313,257],Ea=[65408,65280,257],Fa=["","playlist_id"],Ga=["","channel","clip_id"],x={c:[],p:!1},Ha={c:[],p:!0},Ia={"class":x},z={C:1,EM:1,EMAIL:1,STRONG:1,URL:1,"html:b":1,
"html:code":1,"html:i":1,"html:strong":1,"html:u":1},Ja=[[/(?:open|play)\\.spotify\\.com\\/(?:user\\/[-.\\w]+\\/)?((?:album|artist|episode|playlist|show|track)(?:[:\\/][-.\\w]+)+)/,ha]];function Ka(a,b){var c={},d;for(d in b.b){var l=b.b[d],k=!1;if(d in a.b){k=l.c;var h=d,t=a.b[d];B.x=h;for(var p=0;p<k.length&&(t=k[p](t,h),!1!==t);++p);delete B.x;k=t}!1!==k?c[d]=k:l.p&&C(a)}La(a,c)}
var F=[Ka],Na=[function(a){return Ma(a,/^[-0-9A-Za-z_]+$/)}],Oa={c:[function(a){var b=/^(?=\\d)(?:(\\d+)h)?(?:(\\d+)m)?(?:(\\d+)s)?$/.exec(a);return b?3600*(b[1]||0)+60*(b[2]||0)+(+b[3]||0):/^(?:0|[1-9]\\d*)$/.test(a)?a:!1}],p:!1},Va=[function(a){var b=Pa.urlConfig,c=B,d=Qa(a.replace(/^\\s+/,"").replace(/\\s+$/,""));(b=Ra(b,d))?(c&&(d.attrValue=a,c.add("err",b,d)),a=!1):a=Ua(d);return a}],Wa={c:Na,p:!0},Xa={c:[function(a){return Ma(a,/^[- +,.0-9A-Za-z_]+$/)}],p:!1},Ya={c:Va,p:!0},Za={l:z,flags:268,m:z},
$a={l:z,flags:3460,m:z},ab={d:da,b:{},h:0,c:F,f:10,e:{flags:66},g:5E3},bb={l:{C:1,EM:1,EMAIL:1,LI:1,STRONG:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:li":1,"html:strong":1,"html:u":1},flags:264,m:z},cb={d:ya,b:{},h:0,c:F,f:10,e:{flags:2},g:5E3},db={d:ya,b:{},h:0,c:F,f:10,e:ia,g:5E3},eb={d:xa,b:{},h:0,c:F,f:10,e:{flags:512},g:5E3},fb={l:{C:1,EM:1,EMAIL:1,STRONG:1,URL:1,"html:b":1,"html:code":1,"html:dd":1,"html:dt":1,"html:i":1,"html:strong":1,"html:u":1},flags:256,m:z},gb={l:{C:1,EM:1,EMAIL:1,
STRONG:1,TD:1,TH:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:strong":1,"html:td":1,"html:th":1,"html:u":1},flags:256,m:z},hb={d:va,b:{id:x},h:2,c:F,f:10,e:pa,g:5E3},mb={d:wa,b:{"char":Ha},h:8,c:F,f:10,e:pa,g:5E3},nb={align:{c:[function(a){return a.toLowerCase()},function(a){return Ma(a,/^(?:center|justify|left|right)$/)}],p:!1}},ob={d:ya,b:{},h:3,c:F,f:10,e:{l:z,flags:260,m:z},g:5E3},pb={d:[65408,65290,257],b:{},h:1,c:F,f:10,e:$a,g:5E3},qb={d:Ca,b:{},h:9,c:F,f:10,e:{l:z,flags:3456,m:z},g:5E3},
rb={d:Ba,b:{},h:1,c:F,f:10,e:$a,g:5E3},sb={d:[65408,65284,257],b:{},h:11,c:F,f:10,e:{l:{C:1,EM:1,EMAIL:1,STRONG:1,TD:1,TH:1,TR:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:strong":1,"html:td":1,"html:th":1,"html:tr":1,"html:u":1},flags:3456,m:z},g:5E3},tb={d:Ca,b:{},h:9,c:F,f:10,e:{l:{C:1,EM:1,EMAIL:1,STRONG:1,TBODY:1,TD:1,TH:1,THEAD:1,TR:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:strong":1,"html:tbody":1,"html:td":1,"html:th":1,"html:thead":1,"html:tr":1,"html:u":1},flags:3456,m:z},g:5E3},
ub=\'<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:html="urn:s9e:TextFormatter:html" exclude-result-prefixes="html"><xsl:output method="html" encoding="utf-8" indent="no"/><xsl:decimal-format decimal-separator="."/><xsl:param$pMEDIAEMBED_THEME"/><xsl:param$pTASKLISTS_EDITABLE"/>$aBANDCAMP"><$w$hbandcamp"$k$g400px"><$w$k$c100%"><$t$i"$q$x$nno"$k$f><$l$psrc">//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/<$u><$v$o@album_id">album=$d@album_id"/><$s$o@track_num">/t=$d@track_num"/></$s></$v><$r>track=$d@track_id"/></$r></$u><$s$o$MEDIAEMBED_THEME=\\\'dark\\\'">/bgcol=333333/linkcol=0f91ff</$s></$l></$t></$w></$w>$b$aC"><code>$e</code>$b$aCODE"><pre><code><$s$o@lang"><$l$pclass">language-$d@lang"/></$l></$s>$e</code></pre>$b$aDAILYMOTION"><$w$hdailymotion"$k$g640px"><$w$k$c56.25%"><$t$i"$q$x$nno"$k$f><$l$psrc">//www.dailymotion.com/embed/video/$d@id"/><$s$o@t">?start=$d@t"/></$s></$l></$t></$w></$w>$b$aDEL|EM|H1|H2|H3|H4|H5|H6|STRONG|SUB|SUP|TABLE|TBODY|THEAD|TR|html:b|html:br|html:code|html:dd|html:del|html:dl|html:dt|html:i|html:ins|html:li|html:ol|html:pre|html:rb|html:rp|html:rt|html:rtc|html:ruby|html:strong|html:sub|html:sup|html:table|html:tbody|html:tfoot|html:thead|html:tr|html:u|html:ul|p"><xsl:element$p{translate(local-name(),\\\'ABDEGHLMNOPRSTUY\\\',\\\'abdeghlmnoprstuy\\\')}">$e</xsl:element>$b$aEMAIL"><a href="mailto:{@email}">$e</a>$b$aESC">$e$b$aFACEBOOK"><$t$hfacebook"$i"$mstyle"$q$x onload="var c=new MessageChannel;c.port1.onmessage=function(e){{style.height=e.data+\\\'px\\\'}};contentWindow.postMessage(\\\'s9e:init\\\',\\\'https://s9e.github.io\\\',[c.port2])"$nno"$yhttps://s9e.github.io/$t/2/facebook.min.html#{@type}{@id}"$kborder:0;height:360px;max-width:640px;width:100%"/>$b$aFP|HE">$d@char"/>$b$aHC"><xsl:comment>$d@content"/></xsl:comment>$b$aHR"><hr/>$b$aIMG"><img$y{@src}">$jalt|@title"/></img>$b$aISPOILER"><$w class="spoiler"$mstyle" onclick="removeAttribute(\\\'style\\\')"$kbackground:#444;color:transparent">$e</$w>$b$aLI"><li><$s$oTASK"><$l$pdata-s9e-livepreview-ignore-attrs">data-task-id</$l><$l$pdata-task-id">$dTASK/@id"/></$l><$l$pdata-task-state">$dTASK/@state"/></$l></$s>$e</li>$b$aLIST"><$u><$v$onot(@type)"><ul>$e</ul></$v><$r><ol>$jstart"/>$e</ol></$r></$u>$b$aLIVELEAK"><$w$hliveleak"$k$g640px"><$w$k$c56.25%"><$t$i"$q$x$nno"$y//www.liveleak.com/e/{@id}"$k$f/></$w></$w>$b$aQUOTE"><blockquote>$e</blockquote>$b$aSOUNDCLOUD"><$t$hsoundcloud"$i"$q$x$nno"><$l$psrc">https://w.soundcloud.com/player/?url=<$u><$v$o$z">https%3A//api.soundcloud.com/playlists/$d$z"/></$v><$v$o@track_id">https%3A//api.soundcloud.com/tracks/$d@track_id"/>&amp;secret_token=$d@secret_token"/></$v><$r><$s$onot(contains(@id,\\\'://\\\'))">https%3A//soundcloud.com/</$s>$d@id"/></$r></$u></$l><$l$pstyle">border:0;height:<$u><$v$o$z or contains(@id,\\\'/sets/\\\')">450</$v><$r>166</$r></$u>px;max-width:900px;width:100%</$l></$t>$b$aSPOILER"><details class="spoiler"$mopen">$e</details>$b$aSPOTIFY"><$u><$v$ostarts-with(@id,\\\'episode/\\\')or starts-with(@id,\\\'show/\\\')"><$t$hspotify" allow="encrypted-media"$i"$q$x$nno"$yhttps://open.spotify.com/embed/{@id}"$kborder:0;height:152px;max-width:900px;width:100%"/></$v><$r><$w$hspotify"$k$g320px"><$w$k$c125%;padding-bottom:calc(100% + 80px)"><$t allow="encrypted-media"$i"$q$x$nno"$yhttps://open.spotify.com/embed/{translate(@id,\\\':\\\',\\\'/\\\')}{@path}"$k$f/></$w></$w></$r></$u>$b$aTASK"><input data-task-id="{@id}"$mdata-task-id" type="checkbox"><$s$o@state=\\\'checked\\\'"><$l$pchecked"/></$s><$s$onot($TASKLISTS_EDITABLE)"><$l$pdisabled"/></$s></input>$b$aTD"><td><$s$o@align"><$l$pstyle">text-align:$d@align"/></$l></$s>$e</td>$b$aTH"><th><$s$o@align"><$l$pstyle">text-align:$d@align"/></$l></$s>$e</th>$b$aTWITCH"><$w$htwitch"$k$g640px"><$w$k$c56.25%"><$t$i"$q$x onload="contentWindow.postMessage(\\\'\\\',\\\'https://s9e.github.io\\\')"$nno"$yhttps://s9e.github.io/$t/2/twitch.min.html#channel={@channel};clip_id={@clip_id};t={@t};video_id={@video_id}"$k$f/></$w></$w>$b$aURL"><a href="{@url}">$jtitle"/>$e</a>$b$aVIMEO"><$w$hvimeo"$k$g640px"><$w$k$c56.25%"><$t$i"$q$x$nno"$k$f><$l$psrc">//player.vimeo.com/video/$d@id"/><$s$o@t">#t=$d@t"/></$s></$l></$t></$w></$w>$b$aVINE"><$w$hvine"$k$g480px"><$w$k$c100%"><$t$i"$q$x$nno"$yhttps://vine.co/v/{@id}/embed/simple?audio=1"$k$f/></$w></$w>$b$aYOUTUBE"><$w$hyoutube"$k$g640px"><$w$k$c56.25%"><$t$i"$q$x$nno"$kbackground:url(https://i.ytimg.com/vi/{@id}/hqdefault.jpg) 50% 50% / cover;$f><$l$psrc">https://www.youtube.com/embed/$d@id"/><$s$o@list">?list=$d@list"/></$s><$s$o@t"><$u><$v$o@list">&amp;</$v><$r>?</$r></$u>start=$d@t"/></$s></$l></$t></$w></$w>$b$abr"><br/>$b$ae|i|s"/>$ahtml:abbr"><abbr>$jtitle"/>$e</abbr>$b$ahtml:div"><div>$jclass"/>$e</div>$b$ahtml:img"><img>$jalt|@height|@src|@title|@width"/>$e</img>$b$ahtml:$w"><$w>$jclass"/>$e</$w>$b$ahtml:td"><td>$jcol$w|@row$w"/>$e</td>$b$ahtml:th"><th>$jcol$w|@row$w|@scope"/>$e</th>$b</xsl:stylesheet>\'.replace(/\\$[a-z]/g,
function(a){return{$a:\'<xsl:template match="\',$b:"</xsl:template>",$c:"display:block;overflow:hidden;position:relative;padding-bottom:",$d:\'<xsl:value-of select="\',$e:"<xsl:apply-templates/>",$f:\'border:0;height:100%;left:0;position:absolute;width:100%"\',$g:"display:inline-block;width:100%;max-width:",$h:\' data-s9e-mediaembed="\',$i:\' allowfullscreen="\',$j:\'<xsl:copy-of select="@\',$k:\' style="\',$l:"xsl:attribute",$m:\' data-s9e-livepreview-ignore-attrs="\',$n:\' scrolling="\',$o:\' test="\',$p:\' name="\',
$q:\' loading="\',$r:"xsl:otherwise",$s:"xsl:if",$t:"iframe",$u:"xsl:choose",$v:"xsl:when",$w:"span",$x:\'lazy"\',$y:\' src="\',$z:"@playlist_id"}[a]});function Ma(a,b){return b.test(a)?a:!1}
function Qa(a){var b=/^(?:([a-z][-+.\\w]*):)?(?:\\/\\/(?:([^:\\/?#]*)(?::([^\\/?#]*)?)?@)?(?:(\\[[a-f\\d:]+\\]|[^:\\/?#]+)(?::(\\d*))?)?(?![^\\/?#]))?([^?#]*)(\\?[^#]*)?(#.*)?$/i.exec(a),c={};"scheme user pass host port path query fragment".split(" ").forEach(function(d,l){c[d]=""<b[l+1]?b[l+1]:""});c.scheme=c.scheme.toLowerCase();c.host=c.host.replace(/[\\u3002\\uff0e\\uff61]/g,".").replace(/\\.+$/g,"");/[^\\x00-\\x7F]/.test(c.host)&&"undefined"!==typeof punycode&&(c.host=punycode.toASCII(c.host));return c}
function Ua(a){var b="";""!==a.scheme&&(b+=a.scheme+":");""!==a.host?(b+="//",""!==a.user&&(b+=vb(decodeURIComponent(a.user)),""!==a.pass&&(b+=":"+vb(decodeURIComponent(a.pass))),b+="@"),b+=a.host,""!==a.port&&(b+=":"+a.port)):"file"===a.scheme&&(b+="//");var c=a.path+a.query+a.fragment;c=c.replace(/%.?[a-f]/g,function(d){return d.toUpperCase()},c);b+=c.replace(/[^\\u0020-\\u007E]+/g,encodeURIComponent).replace(/%(?![0-9A-Fa-f]{2})|[^!#-&*-;=?-Z_a-z~]/g,escape);a.scheme||(b=b.replace(/^([^\\/]*):/,"$1%3A"));
return b}
function Ra(a,b){if(""!==b.scheme&&!a.R.test(b.scheme))return"URL scheme is not allowed";if(""!==b.host){var c;if(c=!/^(?!-)[-a-z0-9]{0,62}[a-z0-9](?:\\.(?!-)[-a-z0-9]{0,62}[a-z0-9])*$/i.test(b.host)){a:if(c=b.host,/^\\d+\\.\\d+\\.\\d+\\.\\d+$/.test(c))for(var d=4,l=c.split(".");0<=--d;){if("0"===l[d][0]||255<l[d]){c=!1;break a}}else c=!1;if(c=!c)c=b.host.replace(/^\\[(.*)\\]$/,"$1",b.host),c=!(/^([\\da-f]{0,4}:){2,7}(?:[\\da-f]{0,4}|\\d+\\.\\d+\\.\\d+\\.\\d+)$/.test(c)&&c)}if(c)return"URL host is invalid";if(a.T&&
a.T.test(b.host)||a.W&&!a.W.test(b.host))return"URL host is not allowed"}else if(/^(?:(?:f|ht)tps?)$/.test(b.scheme))return"Missing host"}function wb(a){var b=document.createElement("b");wb=function(c){b.innerHTML=c.replace(/</g,"&lt;");return b.textContent};return wb(a)}function xb(a){var b={"<":"&lt;",">":"&gt;","&":"&amp;",\'"\':"&quot;"};return a.replace(/[<>&"]/g,function(c){return b[c]})}
function yb(a){var b={"<":"&lt;",">":"&gt;","&":"&amp;"};return a.replace(/[<>&]/g,function(c){return b[c]})}function vb(a){return encodeURIComponent(a).replace(/[!\'()*]/g,function(b){return"%"+b.charCodeAt(0).toString(16).toUpperCase()})}function zb(){this.o={};this.q=[]}zb.prototype.add=function(a,b,c){c=c||{};"attrName"in c||!this.x||(c.attrName=this.x);"tag"in c||!this.k||(c.tag=this.k);this.o[a]&&this.o[a].forEach(function(d){d(b,c)});this.q.push([a,b,c])};zb.prototype.getLogs=function(){return this.q};
zb.prototype.on=function(a,b){this.o[a].push(b)};function Ab(a,b){B.add("debug",a,b)}function Bb(a,b,c,d,l){this.k=+a;this.name=b;this.i=+c;this.j=+d;this.q=+l||0;this.b={};this.M=[];isNaN(a+c+d)&&C(this)}Bb.prototype.o=!1;function Cb(a,b){a.M.push(b);a.o&&C(b)}function C(a){a.o||(a.o=!0,a.M.forEach(function(b){C(b)}))}function Db(a,b){Eb(a,b)?(a.y=b,b.F=a,Cb(a,b)):Eb(b,a)&&(a.F=b,b.y=a)}function Eb(a,b){return a.name===b.name&&1===a.k&&2===b.k&&a.i<=a.i}
function Fb(a){var b={},c;for(c in a.b)b[c]=a.b[c];return b}function Gb(a,b){return a.o||!Eb(b,a)||a.F&&a.F!==b||b.y&&b.y!==a?!1:!0}function La(a,b){a.b={};for(var c in b)a.b[c]=b[c]}
var Hb,Ib,G,Jb,I,Kb,B=new zb,Lb,J,K,Ob={Autoemail:{r:function(a,b){b.forEach(function(c){var d=L(1,"EMAIL",c[0][1],0,0);d.b.email=c[0][0];c=Mb("EMAIL",c[0][1]+c[0][0].length,0);Db(d,c)})},u:"@",v:/\\b[-a-z0-9_+.]+@[-a-z0-9.]*[a-z0-9]/ig,w:5E4},Autolink:{r:function(a,b){b.forEach(function(c){var d=c[0][1],l=c[0][0].replace(/(?:(?![-=)\\/_])[\\s!-.:-@[-`{-~])+$/,""),k=d+l.length,h=Mb("URL",k,0);"."===l[3]&&(l="http://"+l);c=L(1,"URL",d,0,1);c.b.url=l;Db(c,h);d=L(3,"v",d,k-d,1E3);Cb(c,d)})},u:":",v:/\\b(?:ftp|https?|mailto):(?:[^\\s()\\[\\]\\uFF01-\\uFF0F\\uFF1A-\\uFF20\\uFF3B-\\uFF40\\uFF5B-\\uFF65]|\\([^\\s()]*\\)|\\[\\w*\\])+/ig,
w:5E4},Escaper:{r:function(a,b){b.forEach(function(c){M("ESC",c[0][1],1,c[0][1]+c[0][0].length,0)})},u:"\\\\",v:/\\\\[-!#()*+.:<>@[\\\\\\]^_`{|}~]/g,w:5E4},FancyPants:{r:function(a){function b(g,n,m,w){g=L(3,r,g,n,w||0);g.b[e]=m;return g}function c(){if(!(0>a.indexOf("...")&&0>a.indexOf("--")))for(var g={"--":"\\u2013","---":"\\u2014","...":"\\u2026"},n=/---?|\\.\\.\\./g,m;m=n.exec(a);)b(m.index,m[0].length,g[m[0]])}function d(){if(!(0>a.indexOf("/")))for(var g={"0/3":"\\u2189","1/10":"\\u2152","1/2":"\\u00bd","1/3":"\\u2153",
"1/4":"\\u00bc","1/5":"\\u2155","1/6":"\\u2159","1/7":"\\u2150","1/8":"\\u215b","1/9":"\\u2151","2/3":"\\u2154","2/5":"\\u2156","3/4":"\\u00be","3/5":"\\u2157","3/8":"\\u215c","4/5":"\\u2158","5/6":"\\u215a","5/8":"\\u215d","7/8":"\\u215e"},n,m=/\\b(?:0\\/3|1\\/(?:[2-9]|10)|2\\/[35]|3\\/[458]|4\\/5|5\\/[68]|7\\/8)\\b/g;n=m.exec(a);)b(n.index,n[0].length,g[n[0]])}function l(){if(!(0>a.indexOf("<<")))for(var g,n=/<<( ?)(?! )[^\\n<>]*?[^\\n <>]\\1>>(?!>)/g;g=n.exec(a);){var m=b(g.index,2,"\\u00ab");g=b(g.index+g[0].length-2,2,
"\\u00bb");Cb(m,g)}}function k(){if(!(0>a.indexOf("!=")&&0>a.indexOf("=/=")))for(var g,n=/\\b (?:!|=\\/)=(?= \\b)/g;g=n.exec(a);)b(g.index+1,g[0].length-1,"\\u2260")}function h(g,n,m,w){for(var y;y=n.exec(a);){var D=b(y.index+y[0].indexOf(g),1,m);y=b(y.index+y[0].length-1,1,w);Cb(D,y)}}function t(){if(f)for(var g,n=/[a-z]\'|(?:^|\\s)\'(?=[a-z]|[0-9]{2})/gi;g=n.exec(a);)b(g.index+g[0].indexOf("\'"),1,"\\u2019",10)}function p(){if(f||q||!(0>a.indexOf("x")))for(var g={"\'s":"\\u2019","\'":"\\u2032","\' ":"\\u2032",
"\'x":"\\u2032",\'"\':"\\u2033",\'" \':"\\u2033",\'"x\':"\\u2033"},n,m=/[0-9](?:\'s|["\']? ?x(?= ?[0-9])|["\'])/g;n=m.exec(a);){"x"===n[0][n[0].length-1]&&b(n.index+n[0].length-1,1,"\\u00d7");var w=n[0].substr(1,2);g[w]&&b(n.index+1,1,g[w])}}function v(){if(!(0>a.indexOf("(")))for(var g={"(c)":"\\u00a9","(r)":"\\u00ae","(tm)":"\\u2122"},n=/\\((?:c|r|tm)\\)/gi,m;m=n.exec(a);)b(m.index,m[0].length,g[m[0].toLowerCase()])}var u={x:"char",I:"FP"},e=u.x,f=0<=a.indexOf("\'"),q=0<=a.indexOf(\'"\'),r=u.I;"undefined"===typeof u.aa&&
(t(),f&&h("\'",/(?:^|\\W)\'.+?\'(?!\\w)/g,"\\u2018","\\u2019"),q&&h(\'"\',/(?:^|\\W)".+?"(?!\\w)/g,"\\u201c","\\u201d"));"undefined"===typeof u.Y&&l();"undefined"===typeof u.Z&&(k(),p(),d());"undefined"===typeof u.$&&c();"undefined"===typeof u.ba&&v()}},HTMLComments:{r:function(a,b){b.forEach(function(c){var d=wb(c[0][0].substr(4,c[0][0].length-7));d=d.replace(/[<>]/g,"");d=d.replace(/-+$/,"");d=d.replace(/--/g,"");L(3,"HC",c[0][1],c[0][0].length,0).b.content=d})},u:"\\x3c!--",v:/\\x3c!--(?!\\[if)[\\s\\S]*?--\\x3e/ig,
w:5E4},HTMLElements:{r:function(a,b){var c={a:{"":"URL",href:"url"},em:{"":"EM"},hr:{"":"HR"},s:{"":"S"},strong:{"":"STRONG"},sup:{"":"SUP"}};b.forEach(function(d){var l="/"===a[d[0][1]+1],k=d[0][1],h=d[0][0].length,t=d[2-l][0].toLowerCase(),p=c&&c[t]&&c[t][""]?c[t][""]:"html:"+t;if(l)Mb(p,k,h);else for(l=/(<\\S+|[\'"\\s])\\/>$/.test(d[0][0])?M(p,k,h,k+h,0):L(1,p,k,h,0),d=d[3][0],k=/([a-z][-a-z0-9]*)(?:\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?/gi;p=k.exec(d);)h=p[1].toLowerCase(),p="undefined"!==typeof p[2]?
p[2]:h,c&&c[t]&&c[t][h]&&(h=c[t][h]),/^["\']/.test(p)&&(p=p.substr(1,p.length-2)),p=wb(p),l.b[h]=p})},u:"<",v:/<(?:\\/((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))|((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))((?:\\s+[a-z][-a-z0-9]*(?:\\s*=\\s*(?:"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?)*)\\s*\\/?)\\s*>/ig,w:5E4},
HTMLEntities:{r:function(a,b){b.forEach(function(c){var d=c[0][0],l=wb(d);l===d||32>l.charCodeAt(0)||(L(3,"HE",c[0][1],d.length,0).b["char"]=l)})},u:"&",v:/&(?:[a-z]+|#(?:[0-9]+|x[0-9a-f]+));/ig,w:5E4},Litedown:{r:function(a){function b(e){-1<e.indexOf("&")&&(e=wb(e));e=e.replace(/\\x1A/g,"");p&&(e=e.replace(/\\x1B./g,function(f){return{"\\u001b0":"!","\\u001b1":\'"\',"\\u001b2":"\'","\\u001b3":"(","\\u001b4":")","\\u001b5":"*","\\u001b6":"<","\\u001b7":">","\\u001b8":"[","\\u001b9":"\\\\","\\u001bA":"]","\\u001bB":"^",
"\\u001bC":"_","\\u001bD":"`","\\u001bE":"~"}[f]}));return e}function c(e){return 0<" abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789".indexOf(e)}function d(e){a=a.substr(0,e)+"\\u0017"+a.substr(e+1)}function l(e,f){0<f&&(a=a.substr(0,e)+Array(1+f).join("\\u001a")+a.substr(e+f))}function k(e,f,q){if(-1!==a.indexOf(e))for(var r;r=f.exec(a);)e=r.index,r=e+r[0].length-2,M(q,e,2,r,2),l(e,2),l(r,2)}function h(e,f,q,r){function g(m){m=a.indexOf(f+"(",m);if(-1!==m){var w;for(r.lastIndex=m;w=r.exec(a);){var y=
w[0];w=w.index;var D=y.length;M(e,w,2,w+D-1,1);l(w,D)}y&&g(m)}}var n=a.indexOf(f);-1!==n&&(function(m){var w;for(q.lastIndex=m;w=q.exec(a);){m=w[0];w=w.index;var y=m.substr(-1)===f?1:0;M(e,w,1,w+m.length-y,y)}}(n),g(n))}function t(e,f,q){var r=f.replace(/^\\s*/,"").replace(/\\s*$/,"");f="";var g=r.indexOf(" ");-1!==g&&(f=r.substr(g).replace(/^\\s*\\S/,"").replace(/\\S\\s*$/,""),r=r.substr(0,g));/^<.+>$/.test(r)&&(r=r.replace(/^<(.+)>$/,"$1").replace(/\\\\>/g,">"));r=b(r);e.b[q]=r;""<f&&(q=b(f),e.b.title=
q)}var p=!1,v=!1,u={};0<=a.indexOf("\\\\")&&(p=!0,a=a.replace(/\\\\[!"\'()*<>[\\\\\\]^_`~]/g,function(e){return{"\\\\!":"\\u001b0",\'\\\\"\':"\\u001b1","\\\\\'":"\\u001b2","\\\\(":"\\u001b3","\\\\)":"\\u001b4","\\\\*":"\\u001b5","\\\\<":"\\u001b6","\\\\>":"\\u001b7","\\\\[":"\\u001b8","\\\\\\\\":"\\u001b9","\\\\]":"\\u001bA","\\\\^":"\\u001bB","\\\\_":"\\u001bC","\\\\`":"\\u001bD","\\\\~":"\\u001bE"}[e]}));a+="\\n\\n\\u0017";(function(){function e(m,w){Db(Mb("LIST",w,0),m.U);Db(Mb("LI",w,0),m.G);m.P&&m.H.forEach(function(y){y.flags&=-9})}function f(m,w){for(var y=
m;0<=--w;)y=y.replace(/^ *>!? ?/,"");return m.length-y.length}function q(m,w){return/[ \\t]*#*[ \\t]*$/.exec(a.substr(m,w-m))[0].length}function r(m){for(var w=[],y=/>!?/g,D;D=y.exec(m);)w.push(D[0]);return w}function g(){if(-1!==a.indexOf("-")||-1!==a.indexOf("="))for(var m,w=/^(?=[-=>])(?:>!? ?)*(?=[-=])(?:-+|=+) *$/gm;m=w.exec(a);){var y=m[0];m=m.index;for(var D=m-1;0<D&&" "===a[D-1];)--D;n[m-1]={K:m+y.length-D,L:D,S:y.length-y.replace(/>/g,"").length,I:"="===y[0]?"H1":"H2"}}}var n={};(function(){g();
for(var m=[],w=0,y,D=4,A,H=!0,Q=[],N=0,ea=!1,S=0,ma,ja,Z,ib,Yb,U,P,jb,Sa,ka,la,sa,Zb=[],kb,Ta=/^(?:(?=[-*+\\d \\t>`~#_])((?: {0,3}>(?:(?!!)|!(?![^\\n>]*?!<)) ?)+)?([ \\t]+)?(\\* *\\* *\\*[* ]*$|- *- *-[- ]*$|_ *_ *_[_ ]*$)?((?:[-*+]|\\d+\\.)[ \\t]+(?=\\S))?[ \\t]*(#{1,6}[ \\t]+|```+[^`\\n]*$|~~~+[^~\\n]*$)?)?/gm;kb=Ta.exec(a);)Zb.push(kb),kb.index===Ta.lastIndex&&++Ta.lastIndex;Zb.forEach(function(E){var aa=[],V=E.index,ta=E[0].length,R;ka=Z=0;ja=!H;U=a.indexOf("\\n",V);H=U===V+ta&&!E[3]&&!E[4]&&!E[5];ta||++Ta.lastIndex;
ma=H&&ja;E[1]&&(aa=r(E[1]),ka=aa.length,Z=E[1].length,A&&"blockDepth"in A.b&&(ka=Math.min(ka,A.b.blockDepth),Z=f(E[1],ka)),l(V,Z));if(ka<w&&!ja){ea=!0;do{var W=m.pop();Db(Mb(W.name,S,0),W)}while(ka<--w)}if(ka>w&&!H){ea=!0;do m.push(L(1,">!"===aa[w]?"SPOILER":"QUOTE",V,0,-999));while(ka>++w)}W=R=0;if(E[2]&&!y){ib=E[2];Yb=ib.length;do" "===ib[W]?++R:R=R+4&-4;while(++W<Yb&&R<D)}A&&!y&&R<D&&!H&&(ea=!0);ea&&(ea=!1,A&&(S>A.i?(l(A.i,S-A.i),Db(A,Mb("CODE",S,0,-1))):C(A),y=A=null),Q.forEach(function(lb){e(lb,
S)}),Q=[],N=0,V&&d(V-1));if(R>=D){if(A||!ja)Z=(E[1]||"").length+W,A||(A=L(1,"CODE",V+Z,0,-999)),E={}}else{aa=!!E[4];if(R||ja||aa)if(ja&&!aa)P=N-1;else if(N)for(P=0;P<N&&R>Q[P].O;)++P;else P=aa?0:-1;else P=-1;for(;P<N-1;)e(Q.pop(),S),--N;P!==N||aa||--P;if(aa&&0<=P)if(ma=!0,la=V+Z+W,sa=E[4].length,W=L(1,"LI",la,sa,0),l(la,sa),P<N)Db(Mb("LI",S,0),Q[P].G),Q[P].G=W,Q[P].H.push(W);else{++N;P?(Sa=Q[P-1].O+1,jb=Math.max(Sa,4*P)):(Sa=0,jb=R);R=L(1,"LIST",la,0,0);if(-1<E[4].indexOf(".")){R.b.type="decimal";
var $b=+E[4];1!==$b&&(R.b.start=$b)}Q.push({U:R,G:W,H:[W],ca:Sa,O:jb,P:!0})}!N||ja||H||(1<Q[0].H.length||!aa)&&Q.forEach(function(lb){lb.P=!1});D=4*(N+1)}if(E[5])if("#"===E[5][0])W=E[5].length,aa=V+ta-W,R=q(V+ta,U),ta=U-R,M("H"+/#{1,6}/.exec(E[5])[0].length,aa,W,ta,R),d(aa),d(U),ja&&(ma=!0);else{if("`"===E[5][0]||"~"===E[5][0])la=V+Z,sa=U-la,A&&E[5]===y?(Db(A,Mb("CODE",la,sa,-1)),Nb(S,la-S),l(A.i,la+sa-A.i),y=A=null):A||(A=L(1,"CODE",la,sa,0),y=E[5].replace(/[^`~]+/,""),A.b.blockDepth=ka,Nb(la+sa,
1),E=E[5].replace(/^[`~\\s]*/,"").replace(/\\s+$/,""),""!==E&&(A.b.lang=E))}else E[3]&&!N&&"\\u0017"!==a[V+ta]?(L(3,"HR",V+Z,ta-Z,0),ma=!0,d(U)):!n[U]||n[U].S!==ka||H||N||A||(M(n[U].I,V+Z,0,n[U].L,n[U].K),d(n[U].L+n[U].K));ma&&(L(3,"pb",S,0,0),d(S));H||(S=U);Z&&Nb(V,Z,1E3)})})()})();(function(){if(!(0>a.indexOf("]:")))for(var e,f=/^\\x1A* {0,3}\\[([^\\x17\\]]+)\\]: *([^[\\s\\x17]+ *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))?) *(?=$|\\x17)\\n?/gm;e=f.exec(a);){Nb(e.index,e[0].length);var q=e[1].toLowerCase();u[q]||
(v=!0,u[q]=e[2])}})();(function(){var e=a.indexOf("`");if(0>e)var f=[];else{f=/(`+)(\\s*)[^\\x17`]*/g;var q=0,r=[],g=a.replace(/\\x1BD/g,"\\\\`");for(f.lastIndex=e;e=f.exec(g);)r.push({i:e.index,j:e[1].length,Q:q,X:e[2].length,next:e.index+e[0].length}),q=e[0].length-e[0].replace(/\\s+$/,"").length;f=r}g=-1;for(q=f.length;++g<q-1;)for(e=f[g].next,r=g,"`"!==a[f[g].i]&&(++f[g].i,--f[g].j);++r<q&&f[r].i===e;){if(f[r].j===f[g].j){g=f[g];var n=f[r];e=g.i;var m=n.i-n.Q;n=n.j+n.Q;M("C",e,g.j+g.X,m,n);l(e,m+n-
e);g=r;break}e=f[r].next}})();(function(){function e(f,q,r,g,n){var m=M("IMG",f,2,q,r);t(m,g,"src");g=b(n);m.b.alt=g;l(f,q+r-f)}(function(){var f=a.indexOf("![");if(-1!==f){if(0<a.indexOf("](",f))for(var q=/!\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:[^\\x17\\s()]|\\([^\\x17\\s()]*\\))*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/g;f=q.exec(a);){var r=f[1],g=f.index,n=3+r.length;e(g,g+f[0].length-n,n,r,f[0].substr(2,f[0].length-n-2))}if(v)for(q=/!\\[((?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*)\\](?: ?\\[([^\\x17[\\]]+)\\])?/g;f=
q.exec(a);){r=f.index;g=r+2+f[1].length;n=1;var m=f[1],w=m;if(""<f[2]&&u[f[2]])n=f[0].length-m.length-2,w=f[2];else if(!u[w])continue;e(r,g,n,u[w],m)}}})()})();k(">!",/>![^\\x17]+?!</g,"ISPOILER");k("||",/\\|\\|[^\\x17]+?\\|\\|/g,"ISPOILER");(function(){function e(g,n,m,w){var y=M("URL",g,1,n,m,1===m?1:-1);t(y,w,"url");l(g,1);l(n,m)}function f(){for(var g,n=/<[-+.\\w]+([:@])[^\\x17\\s>]+?(?:>|\\x1B7)/g;g=n.exec(a);){var m=b(g[0].replace(/\\x1B/g,"\\\\\\u001b")).replace(/^<(.+)>$/,"$1"),w=g.index,y=":"===g[1]?"URL":
"EMAIL",D=y.toLowerCase();M(y,w,1,w+g[0].length-1,1).b[D]=m}}function q(){for(var g,n=/\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:\\([^\\x17\\s()]*\\)|[^\\x17\\s)])*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/g;g=n.exec(a);){var m=g[1],w=g.index,y=3+m.length;e(w,w+g[0].length-y,y,m)}}function r(){for(var g={},n,m=/\\[((?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*)\\]/g;n=m.exec(a);)g[n.index]=n[1].toLowerCase();for(var w in g){n=g[w];m=+w+2+n.length;var y=m-1,D=1;" "===a[m]&&++m;""<g[m]&&u[g[m]]&&(n=g[m],
D=m+2+n.length-y);u[n]&&e(+w,y,D,u[n])}}-1!==a.indexOf("](")&&q();-1!==a.indexOf("<")&&f();v&&r()})();k("~~",/~~[^\\x17]+?~~(?!~)/g,"DEL");h("SUB","~",/~[^\\x17\\s!"#$%&\'()*+,\\-.\\/:;<=>?@[\\]^_`{}|~]+~?/g,/~\\([^\\x17()]+\\)/g);h("SUP","^",/\\^[^\\x17\\s!"#$%&\'()*+,\\-.\\/:;<=>?@[\\]^_`{}|~]+\\^?/g,/\\^\\([^\\x17()]+\\)/g);(function(){function e(D,A){var H=a.indexOf(D);if(-1!==H){D=[];var Q=[],N=a.indexOf("\\u0017",H),ea;for(A.lastIndex=H;ea=A.exec(a);){H=ea.index;ea=ea[0].length;H>N&&(Q.push(D),D=[],N=a.indexOf("\\u0017",
H));var S,ma=H,ja=ea;if(S="_"===a.charAt(ma)&&1===ja)S=0<ma&&c(a[ma-1])&&c(a[ma+ja]);S||D.push([H,ea])}Q.push(D);Q.forEach(f)}}function f(D){w=g=-1;D.forEach(function(A){var H=A[0];A=A[1];var Q=!(-1<" \\n\\t".indexOf(a[H+A-1+1])),N=0<H&&-1<" \\n\\t".indexOf(a.charAt(H-1))?0:Math.min(A,3);q=!!(N&1)&&0<=g;r=!!(N&2)&&0<=w;y=n=H;m=A;0<=g&&g===w&&(q?g+=2:++w);q&&r&&(g<w?n+=2:++y);q&&(--m,M("EM",g,1,n,1),g=-1);r&&(m-=2,M("STRONG",w,2,y,2),w=-1);m=Q?Math.min(m,3):0;H+=A;m&1&&(g=H-m);m&2&&(w=H-m)})}var q,r,g,
n,m,w,y;e("*",/\\*+/g);e("_",/_+/g)})();(function(){for(var e=a.indexOf("  \\n");0<e;)Cb(L(3,"br",e+2,0,0),L(3,"v",e+2,1,0)),e=a.indexOf("  \\n",e+3)})()}},MediaEmbed:{r:function(a,b){b.forEach(function(c){var d=c[0][0];L(3,"MEDIA",c[0][1],d.length,-10).b.url=d})},u:"://",v:/\\bhttps?:\\/\\/[^["\'\\s]+/ig,w:5E4},PipeTables:{r:function(a){function b(u,e){k=e.i;e.D.split("|").forEach(function(f,q){0<q&&(Cb(t,Nb(k,1,1E3)),++k);q=h.J[q]?h.J[q]:"";var r=k,g=r+f.length;k=g;var n=/^( *).*?( *)$/.exec(f);n[1]&&(f=
n[1].length,Cb(t,Nb(r,f,1E3)),r+=f);n[2]&&(f=n[2].length,Cb(t,Nb(g-f,f,1E3)),g-=f);g=r===g?L(3,u,r,0,-101):M(u,r,0,g,0,-101);q&&(g.b.align=q)});M("TR",e.i,0,k,0,-102)}function c(){if(h&&2<h.n.length&&/^ *:?-+:?(?:(?:\\+| *\\| *):?-+:?)+ */.test(h.n[1].D)){for(var u=h,e=h.n[1].D,f=["","right","left","center"],q=[],r=/(:?)-+(:?)/g,g;g=r.exec(e);)q.push(f[(g[1]?2:0)+(g[2]?1:0)]);u.J=q;p.push(h)}h=null}function d(u){return u.replace(/[!>]/g," ")}function l(u){return u.replace(/\\|/g,".")}var k,h=null,t,
p,v=a;-1<v.indexOf("`")&&(v=v.replace(/`[^`]*`/g,l));-1<v.indexOf(">")&&(v=v.replace(/^(?:>!? ?)+/gm,d));-1<v.indexOf("\\\\|")&&(v=v.replace(/\\\\[\\\\|]/g,".."));(function(){h=null;p=[];k=0;v.split("\\n").forEach(function(u){if(0>u.indexOf("|"))c();else{var e=u,f=0;h||(h={n:[]},f=/^ */.exec(e)[0].length,e=e.substr(f));e=e.replace(/^( *)\\|/,"$1 ").replace(/\\|( *)$/," $1");h.n.push({D:e,i:k+f})}k+=1+u.length});c()})();(function(){for(var u=-1,e=p.length;++u<e;){h=p[u];var f=h.n[h.n.length-1];t=M("TABLE",
h.n[0].i,0,f.i+f.D.length,0,-104);b("TH",h.n[0]);M("THEAD",h.n[0].i,0,k,0,-103);f=h.n[1];Cb(t,Nb(f.i-1,1+f.D.length,1E3));f=1;for(var q=h.n.length;++f<q;)b("TD",h.n[f]);M("TBODY",h.n[2].i,0,k,0,-103)}})()},u:"|"}},O,Pa={"MediaEmbed.hosts":{"bandcamp.com":"bandcamp","dai.ly":"dailymotion","dailymotion.com":"dailymotion","facebook.com":"facebook","link.tospotify.com":"spotify","liveleak.com":"liveleak","open.spotify.com":"spotify","play.spotify.com":"spotify","soundcloud.com":"soundcloud","twitch.tv":"twitch",
"vimeo.com":"vimeo","vine.co":"vine","youtu.be":"youtube","youtube.com":"youtube"},"MediaEmbed.sites":{bandcamp:[[],[{z:[[/\\/album=(\\d+)/,ra]],match:[[/bandcamp\\.com\\/album\\/./,ca]]},{z:[[/"album_id":(\\d+)/,ra],[/"track_num":(\\d+)/,["","track_num"]],[/\\/track=(\\d+)/,ua]],match:[[/bandcamp\\.com\\/track\\/./,ca]]}]],dailymotion:[[[/dai\\.ly\\/([a-z0-9]+)/i,ha],[/dailymotion\\.com\\/(?:live\\/|swf\\/|user\\/[^#]+#video=|(?:related\\/\\d+\\/)?video\\/)([a-z0-9]+)/i,ha],[/start=(\\d+)/,fa]],[]],facebook:[[[/\\/(?!(?:apps|developers|graph)\\.)[-\\w.]*facebook\\.com\\/(?:[\\/\\w]+\\/permalink|(?!marketplace\\/|pages\\/|groups\\/).*?)(?:\\/|fbid=|\\?v=)(\\d+)(?=$|[\\/?&#])/,
ha],[/facebook\\.com\\/([.\\w]+)\\/(?=(?:post|video)s?\\/)([pv])/,["","user","type"]],[/facebook\\.com\\/video\\/(?=post|video)([pv])/,na],[/facebook\\.com\\/watch\\/\\?([pv])=/,na]],[]],liveleak:[[[/liveleak\\.com\\/(?:e\\/|view\\?i=)(\\w+)/,ha]],[{z:[[/liveleak\\.com\\/e\\/(\\w+)/,ha]],match:[[/liveleak\\.com\\/view\\?t=/,ca]]}]],soundcloud:[[[/https?:\\/\\/(?:api\\.)?soundcloud\\.com\\/(?!pages\\/)([-\\/\\w]+\\/[-\\/\\w]+|^[^\\/]+\\/[^\\/]+$)/i,ha],[/api\\.soundcloud\\.com\\/playlists\\/(\\d+)/,Fa],[/api\\.soundcloud\\.com\\/tracks\\/(\\d+)(?:\\?secret_token=([-\\w]+))?/,
["","track_id","secret_token"]],[/soundcloud\\.com\\/(?!playlists|tracks)[-\\w]+\\/[-\\w]+\\/(?=s-)([-\\w]+)/,["","secret_token"]]],[{z:[[/soundcloud:tracks:(\\d+)/,ua]],N:"User-agent: PHP (not Mozilla)",match:[[/soundcloud\\.com\\/(?!playlists\\/\\d|tracks\\/\\d)[-\\w]+\\/[-\\w]/,ca]]},{z:[[/soundcloud:\\/\\/playlists:(\\d+)/,Fa]],N:"User-agent: PHP (not Mozilla)",match:[[/soundcloud\\.com\\/\\w+\\/sets\\//,ca]]}]],spotify:[Ja,[{z:Ja,N:"User-agent: PHP (not Mozilla)",match:[[/link\\.tospotify\\.com\\/./,ca]]}]],twitch:[[[/twitch\\.tv\\/(?:videos|\\w+\\/v)\\/(\\d+)?/,
["","video_id"]],[/www\\.twitch\\.tv\\/(?!videos\\/)(\\w+)(?:\\/clip\\/(\\w+))?/,Ga],[/t=((?:(?:\\d+h)?\\d+m)?\\d+s)/,fa],[/clips\\.twitch\\.tv\\/(?:(\\w+)\\/)?(\\w+)/,Ga]],[]],vimeo:[[[/vimeo\\.com\\/(?:channels\\/[^\\/]+\\/|video\\/)?(\\d+)/,ha],[/#t=([\\dhms]+)/,fa]],[]],vine:[[[/vine\\.co\\/v\\/([^\\/]+)/,ha]],[]],youtube:[[[/youtube\\.com\\/(?:watch.*?v=|v\\/|attribution_link.*?v%3D)([-\\w]+)/,ha],[/youtu\\.be\\/([-\\w]+)/,ha],[/[#&?]t=(\\d[\\dhms]*)/,fa],[/[&?]list=([-\\w]+)/,["","list"]]],[{z:[[/\\/vi\\/([-\\w]+)/,ha]],match:[[/\\/shared\\?ci=/,
ca]]}]]},urlConfig:{R:/^(?:ftp|https?|mailto)$/i}},Pb={d:xa,flags:8},X={BANDCAMP:{d:va,b:{album_id:x,track_id:x,track_num:x},h:2,c:F,f:10,e:pa,g:5E3},C:ab,CODE:{d:da,b:{lang:Xa},h:1,c:F,f:10,e:{l:z,flags:4436,m:z},g:5E3},DAILYMOTION:{d:va,b:{id:x,t:x},h:2,c:F,f:10,e:pa,g:5E3},DEL:eb,EM:cb,EMAIL:{d:za,b:{email:{c:[function(a){return/^[-\\w.+]+@[-\\w.]+$/.test(a)?a:!1}],p:!0}},h:2,c:F,f:10,e:oa,g:5E3},ESC:{d:da,b:{},h:7,c:F,f:10,e:{flags:1616},g:5E3},FACEBOOK:{d:va,b:{id:x,type:x,user:x},h:2,c:F,f:10,
e:pa,g:5E3},FP:mb,H1:ob,H2:ob,H3:ob,H4:ob,H5:ob,H6:ob,HC:{d:da,b:{content:Ha},h:7,c:F,f:10,e:{flags:3153},g:5E3},HE:mb,HR:{d:wa,b:{},h:1,c:F,f:10,e:{l:z,flags:3349},g:5E3},IMG:{d:wa,b:{alt:x,src:Ya,title:x},h:0,c:F,f:10,e:pa,g:5E3},ISPOILER:db,LI:{d:Aa,b:{},h:4,c:[Ka,function(a){for(var b=a.i+a.j;" "===T.charAt(b);)++b;var c=T.substr(b,3);if(/\\[[ Xx]\\]/.test(c)){var d=Math.random().toString(16).substr(2);c="[ ]"===c?"unchecked":"checked";b=L(3,"TASK",b,3,0);b.b.id=d;b.b.state=c;Cb(a,b)}}],f:10,e:bb,
g:5E3},LIST:{d:Ba,b:{start:{c:[function(a){return/^(?:0|[1-9]\\d*)$/.test(a)?a:!1}],p:!1},type:Xa},h:1,c:F,f:10,e:$a,g:5E3},LIVELEAK:hb,MEDIA:{d:[65519,65329,256],b:{},h:16,c:[function(a){return function(b,c,d){function l(k,h,t){var p=!1;t.forEach(function(v){var u=v[1],e=v[0].exec(h);e&&(p=!0,u.forEach(function(f,q){""<e[q]&&""<f&&(k[f]=e[q])}))});return p}(function(k,h,t){C(k);if("url"in k.b){var p=k.b.url,v;a:{for(v=/^https?:\\/\\/([^\\/]+)/.exec(p.toLowerCase())[1]||"";""<v;){if(h[v]){v=h[v];break a}v=
v.replace(/^[^.]*./,"")}v=""}if(t[v]){h={};l(h,p,t[v][0]);a:{for(var u in h){t=!1;break a}t=!0}if(!t){t=k.i;var e=k.y;e?(p=k.j,u=e.i,e=e.j):(p=0,u=k.i+k.j,e=0);k=M(v.toUpperCase(),t,p,u,e,k.q);La(k,h)}}}})(b,c,d)}(a,Pa["MediaEmbed.hosts"],Pa["MediaEmbed.sites"],Pa.cacheDir)}],f:10,e:{flags:513},g:5E3},QUOTE:{d:Aa,b:{},h:1,c:F,f:10,e:Za,g:5E3},SOUNDCLOUD:{d:va,b:{id:x,playlist_id:x,secret_token:x,track_id:x},h:2,c:F,f:10,e:pa,g:5E3},SPOILER:{d:Aa,b:{},h:5,c:F,f:10,e:Za,g:5E3},SPOTIFY:hb,STRONG:cb,
SUB:db,SUP:db,TABLE:pb,TASK:{d:wa,b:{id:Wa,state:Wa},h:2,c:F,f:10,e:pa,g:5E3},TBODY:tb,TD:{d:Aa,b:nb,h:10,c:F,f:10,e:gb,g:5E3},TH:{d:Da,b:nb,h:10,c:F,f:10,e:gb,g:5E3},THEAD:qb,TR:sb,TWITCH:{d:va,b:{channel:x,clip_id:x,t:x,video_id:x},h:2,c:F,f:10,e:pa,g:5E3},URL:{d:za,b:{title:x,url:Ya},h:6,c:F,f:10,e:oa,g:5E3},VIMEO:{d:va,b:{id:x,t:Oa},h:2,c:F,f:10,e:pa,g:5E3},VINE:hb,YOUTUBE:{d:va,b:{id:{c:Na,p:!1},list:x,t:Oa},h:2,c:F,f:10,e:pa,g:5E3},"html:abbr":{d:ya,b:{title:x},h:0,c:F,f:10,e:ia,g:5E3},"html:b":cb,
"html:br":{d:Ea,b:{},h:0,c:F,f:10,e:qa,g:5E3},"html:code":ab,"html:dd":{d:Aa,b:{},h:12,c:F,f:10,e:fb,g:5E3},"html:del":eb,"html:div":{d:xa,b:Ia,h:13,c:F,f:10,e:Za,g:5E3},"html:dl":{d:[65408,65328,257],b:{},h:1,c:F,f:10,e:$a,g:5E3},"html:dt":{d:Da,b:{},h:12,c:F,f:10,e:fb,g:5E3},"html:i":cb,"html:img":{d:Ea,b:{alt:x,height:x,src:{c:Va,p:!1},title:x,width:x},h:0,c:F,f:10,e:qa,g:5E3},"html:ins":eb,"html:li":{d:Aa,b:{},h:4,c:F,f:10,e:bb,g:5E3},"html:ol":rb,"html:pre":{d:ya,b:{},h:1,c:F,f:10,e:{l:z,flags:276,
m:z},g:5E3},"html:rb":{d:ya,b:{},h:14,c:F,f:10,e:{l:{C:1,EM:1,EMAIL:1,STRONG:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:rb":1,"html:rt":1,"html:rtc":1,"html:strong":1,"html:u":1},flags:256,m:z},g:5E3},"html:rp":{d:ya,b:{},h:15,c:F,f:10,e:{l:z,flags:256,m:z},g:5E3},"html:rt":{d:ya,b:{},h:15,c:F,f:10,e:{l:{C:1,EM:1,EMAIL:1,STRONG:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:rb":1,"html:rt":1,"html:strong":1,"html:u":1},flags:256,m:z},g:5E3},"html:rtc":{d:[65477,65409,257],b:{},h:14,c:F,
f:10,e:{l:{C:1,EM:1,EMAIL:1,STRONG:1,URL:1,"html:b":1,"html:code":1,"html:i":1,"html:rt":1,"html:rtc":1,"html:strong":1,"html:u":1},flags:256,m:z},g:5E3},"html:ruby":{d:[65477,65473,257],b:{},h:0,c:F,f:10,e:ia,g:5E3},"html:span":{d:ya,b:Ia,h:0,c:F,f:10,e:ia,g:5E3},"html:strong":cb,"html:sub":db,"html:sup":db,"html:table":pb,"html:tbody":tb,"html:td":{d:Aa,b:{colspan:x,rowspan:x},h:10,c:F,f:10,e:gb,g:5E3},"html:tfoot":tb,"html:th":{d:Da,b:{colspan:x,rowspan:x,scope:x},h:10,c:F,f:10,e:gb,g:5E3},"html:thead":qb,
"html:tr":sb,"html:u":cb,"html:ul":rb},Y,Qb,T,Rb,Sb=0,Tb;
function Ub(a){a=a.replace(/\\r\\n?/g,"\\n");a=a.replace(/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]+/g,"");var b=B;b.q=[];delete b.x;delete b.k;Hb={};Ib={};Jb=0;I=null;Kb=!1;Lb={};J=[];K="";O=0;Y=[];Qb=!1;T=a;Rb=T.length;Tb=0;G=Pb;G.A=!1;++Sb;a=Sb;for(var c in Ob)if(!Ob[c].B)a:{b=c;var d=Ob[b];if(!(d.u&&0>T.indexOf(d.u))){var l=[];if("undefined"!==typeof d.v&&"undefined"!==typeof d.w){l=void 0;var k=d.v;d=d.w;k.lastIndex=0;for(var h=[],t=0;++t<=d&&(l=k.exec(T));){for(var p=l.index,v=[[l[0],p]],u=0;++u<l.length;){var e=
l[u];void 0===e?v.push(["",-1]):(v.push([e,T.indexOf(e,p)]),p+=e.length)}h.push(v)}l=h;if(!l.length)break a}(0,Ob[b].r)(T,l)}}Vb();Wb(Rb,0,!0);do c=K,K=K.replace(/<([^ />]+)[^>]*><\\/\\1>/g,"");while(K!==c);K=K.replace(/<\\/i><i>/g,"");K=K.replace(/[\\x00-\\x08\\x0B-\\x1F]/g,"");K=K.replace(/[\\uD800-\\uDBFF][\\uDC00-\\uDFFF]/g,Xb);b=Kb?"r":"t";c="<"+b;for(var f in Lb)c+=" xmlns:"+f+\'="urn:s9e:TextFormatter:\'+f+\'"\';K=c+">"+K+"</"+b+">";if(Sb!==a)throw"The parser has been reset during execution";1E4<Jb&&B.add("warn",
"Fixing cost limit exceeded",void 0);return K}function ac(a){var b={},c;for(c in X[a])b[c]=X[a][c];return X[a]=b}function Xb(a){return"&#"+((a.charCodeAt(0)<<10)+a.charCodeAt(1)-56613888)+";"}
function bc(a){Kb=!0;var b=a.name,c=a.i,d=a.j,l=a.flags,k=0,h=0;l&256&&(k=1,h=a.k&2?2:1);var t=!1;a.k&1?l&4&&(t=!0):t=!0;Wb(c,k,t);k=d?yb(T.substr(c,d)):"";if(a.k&1){l&4||cc(c);l=b.indexOf(":");0<l&&(Lb[b.substr(0,l)]=0);K+="<"+b;var p=Fb(a);l=[];for(var v in p)l.push(v);l.sort(function(u,e){return u>e?1:-1});l.forEach(function(u){K+=" "+u+\'="\'+xb(p[u].toString()).replace(/\\n/g,"&#10;")+\'"\'});K=3===a.k?d?K+(">"+k+"</"+b+">"):K+"/>":d?K+("><s>"+k+"</s>"):K+">"}else d&&(K+="<e>"+k+"</e>"),K+="</"+b+
">";for(Tb=O=c+d;h&&Tb<Rb&&"\\n"===T[Tb];)--h,++Tb}
function Wb(a,b,c){c&&(G.flags&8?b=-1:c=!1);O>=a&&c&&dc();if(Tb>O){var d=Math.min(a,Tb);K+=T.substr(O,d-O);O=d;O>=a&&c&&dc()}if(G.flags&128)d=a-O,b=T.substr(O,d),/^[ \\n\\t]*$/.test(b)||(b="<i>"+yb(b)+"</i>"),K+=b,O=a,c&&dc();else{var l=a;for(d=0;b&&--l>=O;){var k=T[l];if(" "!==k&&"\\n"!==k&&"\\t"!==k)break;"\\n"===k&&--b;++d}a-=d;if(G.flags&8)for(G.A||(ec(a),a>O&&cc(a)),b=T.indexOf("\\n\\n",O);-1<b&&b<a;)Wb(b,0,!0),cc(a),b=T.indexOf("\\n\\n",O);a>O&&(b=yb(T.substr(O,a-O)),K+=b);c&&dc();d&&(K+=T.substr(a,
d));O=a+d}}function fc(a){var b=a.i;a=a.j;var c=T.substr(b,a);Wb(b,0,!1);K+="<i>"+yb(c)+"</i>";Kb=!0;O=b+a}function cc(a){!G.A&&G.flags&8&&(ec(a),O<Rb&&(K+="<p>",G.A=!0))}function dc(){G.A&&(K+="</p>",G.A=!1)}function ec(a){for(;O<a&&-1<" \\n\\t".indexOf(T[O]);)K+=T[O],++O}function gc(a,b,c){var d=a.name;(I.flags|a.flags)&256&&(b=hc(b));b=Mb(d,b,0,c||0);Db(b,a)}function hc(a){for(;a>O&&-1<" \\n\\t".indexOf(T[a-1]);)--a;return a}
function Vb(){if(Y.length){for(var a in X)Hb[a]=0,Ib[a]=0;do{for(;Y.length;)Qb||ic(),I=Y.pop(),jc();J.forEach(function(b){gc(b,Rb)})}while(Y.length)}}
function jc(){G.flags&64&&!Gb(I,J[J.length-1])&&!(-1<"br i pb v".indexOf(I.name))&&C(I);var a=I.i,b=I.j;if(O>a&&!I.o){var c;if((c=I.F)&&0<=J.indexOf(c)){Db(Mb(c.name,O,Math.max(0,a+b-O)),c);return}if("i"===I.name&&(a=a+b-O,0<a)){Nb(O,a);return}C(I)}if(!I.o)if("i"===I.name)fc(I);else if("br"===I.name)G.flags&1024||(Wb(I.i,0,!1),K+="<br/>");else if("pb"===I.name)Wb(I.i,0,!0);else if("v"===I.name)a=G.flags,G.flags=I.flags,Wb(I.i+I.j,0,!1),G.flags=a;else if(I.k&1)if(a=I,b=a.name,c=X[b],Ib[b]>=c.g)B.add("err",
"Tag limit exceeded",{tag:a,tagName:b,tagLimit:c.g}),C(a);else{var d=a,l=X[d.name];B.k=d;for(var k=0;k<l.c.length&&!d.o;++k)l.c[k](d,l);delete B.k;if(!(d=a.o)&&(d=1E4>Jb)){a:{d=a;if(J.length){k=d.name;var h=X[k];if(h.e.m){l=J[J.length-1];var t=l.name;if(h.e.m[t]){if(t!==k&&1E4>Jb){k=d.i+d.j;Y.length?(h=Y[Y.length-1],h=h.i):h=Rb+1;for(;k<h&&-1<" \\n\\t".indexOf(T[k]);)++k;k=kc(l,k);Cb(d,k)}Y.push(d);gc(l,d.i,d.q-1);Jb+=4;d=!0;break a}}}d=!1}if(!d)a:{d=a;if(J.length&&(l=X[d.name],l.e.l&&(k=J[J.length-
1],l.e.l[k.name]))){++Jb;Y.push(d);gc(k,d.i,d.q-1);d=!0;break a}d=!1}d=d||!1}d||(Hb[b]>=c.f?(B.add("err","Nesting limit exceeded",{tag:a,tagName:b,nestingLimit:c.f}),C(a)):(c=X[b].h,G.d[c>>3]&1<<(c&7)?(!(a.flags&1&&3!==a.k)||a.y||Y.length&&Gb(Y[Y.length-1],a)||(b=new Bb(3,b,a.i,a.j),La(b,Fb(a)),b.flags=a.flags,a=b),a.flags&4096&&"\\n"===T[a.i+a.j]&&Nb(a.i+a.j,1),bc(a),lc(a)):(b={tag:a,tagName:b},0<a.j?B.add("warn","Tag is not allowed in this context",b):Ab("Tag is not allowed in this context",b),C(a))))}else mc()}
function mc(){var a=I;if(Hb[a.name]){for(var b=[],c=J.length;0<=--c;){var d=J[c];if(Gb(a,d))break;b.push(d);++Jb}if(0>c)Ab("Skipping end tag with no start tag",{tag:a});else{var l=a.flags;b.forEach(function(u){l|=u.flags});var k=l&256,h=1E4>Jb,t=[];b.forEach(function(u){var e=u.name;h&&(u.flags&2?t.push(u):h=!1);var f=a.i;k&&(f=hc(f));e=new Bb(2,e,f,0);e.flags=u.flags;bc(e);nc()});bc(a);nc();if(b.length&&1E4>Jb){d=O;for(c=Y.length;0<=--c&&1E4>++Jb;){var p=Y[c];if(p.i>d||p.k&1)break;for(var v=b.length;0<=
--v&&1E4>++Jb;)if(Gb(p,b[v])){b.splice(v,1);t[v]&&t.splice(v,1);d=Math.max(d,p.i+p.j);break}}d>O&&fc(new Bb(3,"i",O,d-O))}t.forEach(function(u){var e=kc(u,O);(u=u.y)&&Db(e,u)})}}}function nc(){var a=J.pop();--Hb[a.name];G=G.V}function lc(a){var b=a.name,c=a.flags,d=X[b];++Ib[b];if(3!==a.k){var l=[];G.d.forEach(function(h,t){c&512||(h=h&65280|h>>8);l.push(d.d[t]&h)});var k=c|G.flags&32;k&16&&(k&=-33);++Hb[b];J.push(a);G={V:G};G.d=l;G.flags=k}}function Mb(a,b,c,d){return L(2,a,b,c,d||0)}
function Nb(a,b,c){return L(3,"i",a,Math.min(b,Rb-a),c||0)}function kc(a,b){b=L(a.k,a.name,b,0,a.q);La(b,Fb(a));return b}function L(a,b,c,d,l){a=new Bb(a,b,c,d,l||0);X[b]&&(a.flags=X[b].e.flags);if(!(X[b]||-1<"br i pb v".indexOf(a.name))||0>d||0>c||c+d>Rb||/[\\uDC00-\\uDFFF]/.test(T.substr(c,1)+T.substr(c+d,1)))C(a);else if(X[b]&&X[b].B)B.add("warn","Tag is disabled",{tag:a,tagName:b}),C(a);else if(Qb){b=Y.length;for(c=oc(a);0<b&&c>oc(Y[b-1]);)Y[b]=Y[b-1],--b;Y[b]=a}else Y.push(a);return a}
function M(a,b,c,d,l,k){d=Mb(a,d,l,-k||0);a=L(1,a,b,c,k||0);Db(a,d);return a}function ic(){for(var a={},b=[],c=Y.length;0<=--c;){var d=Y[c],l=oc(d,c);b.push(l);a[l]=d}b.sort();c=b.length;for(Y=[];0<=--c;)Y.push(a[b[c]]);Qb=!0}function oc(a,b){var c=0<=a.q,d=a.q;c||(d+=1073741824);var l=0<a.j,k;l?k=Rb-a.j:k={2:0,3:1,1:2}[a.k];return pc(a.i)+ +c+pc(d)+ +l+pc(k)+pc(b||0)}function pc(a){a=a.toString(16);return"        ".substr(a.length)+a}var qc="undefined"===typeof DOMParser||"undefined"===typeof XSLTProcessor;
function rc(a){if(qc){var b=new ActiveXObject("MSXML2.FreeThreadedDOMDocument.6.0");b.async=!1;b.validateOnParse=!1;b.loadXML(a)}else b=(new DOMParser).parseFromString(a,"text/xml");if(!b)throw"Cannot parse "+a;return b}function sc(a,b){if(qc){var c=b.createElement("div");b=b.createDocumentFragment();tc.input=rc(a);tc.transform();for(c.innerHTML=tc.output;c.firstChild;)b.appendChild(c.firstChild);return b}return tc.transformToFragment(rc(a),b)}var tc,uc=rc(ub);
if(qc){var vc=new ActiveXObject("MSXML2.XSLTemplate.6.0");vc.stylesheet=uc;tc=vc.createProcessor()}else tc=new XSLTProcessor,tc.importStylesheet(uc);window.s9e||(window.s9e={});
window.s9e.TextFormatter={disablePlugin:function(a){Ob[a]&&(Ob[a].B=!0)},disableTag:function(a){X[a]&&(ac(a).B=!0)},enablePlugin:function(a){Ob[a]&&(Ob[a].B=!1)},enableTag:function(a){X[a]&&(ac(a).B=!1)},getLogger:function(){return B},parse:Ub,preview:function(a,b){function c(h,t){var p=h.childNodes;t=t.childNodes;for(var v=p.length,u=t.length,e,f,q=0,r=0;q<v&&q<u;){e=p[q];f=t[q];if(!d(e,f))break;++q}for(var g=Math.min(v-q,u-q);r<g;){e=p[v-(r+1)];f=t[u-(r+1)];if(!d(e,f))break;++r}for(v-=r;--v>=q;)h.removeChild(p[v]),
k=h;p=u-r;if(!(q>=p)){u=l.createDocumentFragment();v=q;do f=t[v],k=u.appendChild(f);while(v<--p);r?h.insertBefore(u,h.childNodes[q]):h.appendChild(u)}}function d(h,t){if(h.nodeName!==t.nodeName||h.nodeType!==t.nodeType)return!1;if(h instanceof HTMLElement&&t instanceof HTMLElement){if(!h.isEqualNode(t)){for(var p=h.attributes,v=t.attributes,u=v.length,e=p.length,f=" "+h.getAttribute("data-s9e-livepreview-ignore-attrs")+" ";0<=--e;){var q=p[e],r=q.namespaceURI;q=q.name;-1<f.indexOf(" "+q+" ")||t.hasAttributeNS(r,
q)||(h.removeAttributeNS(r,q),k=h)}for(e=u;0<=--e;)p=v[e],r=p.namespaceURI,q=p.name,p=p.value,-1<f.indexOf(" "+q+" ")||p===h.getAttributeNS(r,q)||(h.setAttributeNS(r,q,p),k=h);c(h,t)}}else 3!==h.nodeType&&8!==h.nodeType||h.nodeValue===t.nodeValue||(h.nodeValue=t.nodeValue,k=h);return!0}var l=b.ownerDocument;if(!l)throw"Target does not have a ownerDocument";a=sc(Ub(a).replace(/<[eis]>[^<]*<\\/[eis]>/g,""),l);var k=b;"undefined"!==typeof window&&"chrome"in window&&a.querySelectorAll("script").forEach(function(h){var t=
document.createElement("script");var p=h.attributes;var v="undefined"!=typeof Symbol&&Symbol.iterator&&p[Symbol.iterator];p=v?v.call(p):{next:ba(p)};for(v=p.next();!v.done;v=p.next())v=v.value,t.setAttribute(v.name,v.value);t.textContent=h.textContent;h.parentNode.replaceChild(t,h)});c(b,a);return k},registeredVars:Pa,setNestingLimit:function(a,b){X[a]&&(ac(a).f=b)},setParameter:function(a,b){qc?tc.addParameter(a,b,""):tc.setParameter(null,a,b)},setTagLimit:function(a,b){X[a]&&(ac(a).g=b)}};})();';
	}
	public static function getParser()
	{
		return unserialize('O:24:"s9e\\TextFormatter\\Parser":4:{s:16:"' . "\0" . '*' . "\0" . 'pluginsConfig";a:10:{s:9:"Autoemail";a:5:{s:8:"attrName";s:5:"email";s:10:"quickMatch";s:1:"@";s:6:"regexp";s:39:"/\\b[-a-z0-9_+.]+@[-a-z0-9.]*[a-z0-9]/Si";s:7:"tagName";s:5:"EMAIL";s:11:"regexpLimit";i:50000;}s:8:"Autolink";a:5:{s:8:"attrName";s:3:"url";s:6:"regexp";s:135:"#\\b(?:ftp|https?|mailto):(?>[^\\s()\\[\\]\\x{FF01}-\\x{FF0F}\\x{FF1A}-\\x{FF20}\\x{FF3B}-\\x{FF40}\\x{FF5B}-\\x{FF65}]|\\([^\\s()]*\\)|\\[\\w*\\])++#Siu";s:7:"tagName";s:3:"URL";s:10:"quickMatch";s:1:":";s:11:"regexpLimit";i:50000;}s:7:"Escaper";a:4:{s:10:"quickMatch";s:1:"\\";s:6:"regexp";s:30:"/\\\\[-!#()*+.:<>@[\\\\\\]^_`{|}~]/";s:7:"tagName";s:3:"ESC";s:11:"regexpLimit";i:50000;}s:10:"FancyPants";a:2:{s:8:"attrName";s:4:"char";s:7:"tagName";s:2:"FP";}s:12:"HTMLComments";a:5:{s:8:"attrName";s:7:"content";s:10:"quickMatch";s:4:"<!--";s:6:"regexp";s:22:"/<!--(?!\\[if).*?-->/is";s:7:"tagName";s:2:"HC";s:11:"regexpLimit";i:50000;}s:12:"HTMLElements";a:5:{s:10:"quickMatch";s:1:"<";s:6:"prefix";s:4:"html";s:6:"regexp";s:385:"#<(?>/((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))|((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))((?>\\s+[a-z][-a-z0-9]*(?>\\s*=\\s*(?>"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?)*+)\\s*/?)\\s*>#i";s:7:"aliases";a:6:{s:1:"a";a:2:{s:0:"";s:3:"URL";s:4:"href";s:3:"url";}s:2:"hr";a:1:{s:0:"";s:2:"HR";}s:2:"em";a:1:{s:0:"";s:2:"EM";}s:1:"s";a:1:{s:0:"";s:1:"S";}s:6:"strong";a:1:{s:0:"";s:6:"STRONG";}s:3:"sup";a:1:{s:0:"";s:3:"SUP";}}s:11:"regexpLimit";i:50000;}s:12:"HTMLEntities";a:5:{s:8:"attrName";s:4:"char";s:10:"quickMatch";s:1:"&";s:6:"regexp";s:38:"/&(?>[a-z]+|#(?>[0-9]+|x[0-9a-f]+));/i";s:7:"tagName";s:2:"HE";s:11:"regexpLimit";i:50000;}s:8:"Litedown";a:1:{s:18:"decodeHtmlEntities";b:1;}s:10:"MediaEmbed";a:4:{s:10:"quickMatch";s:3:"://";s:6:"regexp";s:26:"/\\bhttps?:\\/\\/[^["\'\\s]+/Si";s:7:"tagName";s:5:"MEDIA";s:11:"regexpLimit";i:50000;}s:10:"PipeTables";a:3:{s:16:"overwriteEscapes";b:1;s:17:"overwriteMarkdown";b:1;s:10:"quickMatch";s:1:"|";}}s:14:"registeredVars";a:3:{s:9:"urlConfig";a:1:{s:14:"allowedSchemes";s:27:"/^(?:ftp|https?|mailto)$/Di";}s:16:"MediaEmbed.hosts";a:14:{s:12:"bandcamp.com";s:8:"bandcamp";s:6:"dai.ly";s:11:"dailymotion";s:15:"dailymotion.com";s:11:"dailymotion";s:12:"facebook.com";s:8:"facebook";s:12:"liveleak.com";s:8:"liveleak";s:14:"soundcloud.com";s:10:"soundcloud";s:18:"link.tospotify.com";s:7:"spotify";s:16:"open.spotify.com";s:7:"spotify";s:16:"play.spotify.com";s:7:"spotify";s:9:"twitch.tv";s:6:"twitch";s:9:"vimeo.com";s:5:"vimeo";s:7:"vine.co";s:4:"vine";s:11:"youtube.com";s:7:"youtube";s:8:"youtu.be";s:7:"youtube";}s:16:"MediaEmbed.sites";a:10:{s:8:"bandcamp";a:2:{i:0;a:0:{}i:1;a:2:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:25:"!/album=(?\'album_id\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:8:"album_id";}}}s:5:"match";a:1:{i:0;a:2:{i:0;s:23:"!bandcamp\\.com/album/.!";i:1;a:1:{i:0;s:0:"";}}}}i:1;a:2:{s:7:"extract";a:3:{i:0;a:2:{i:0;s:29:"!"album_id":(?\'album_id\'\\d+)!";i:1;R:91;}i:1;a:2:{i:0;s:31:"!"track_num":(?\'track_num\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:9:"track_num";}}i:2;a:2:{i:0;s:25:"!/track=(?\'track_id\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:8:"track_id";}}}s:5:"match";a:1:{i:0;a:2:{i:0;s:23:"!bandcamp\\.com/track/.!";i:1;R:97;}}}}}s:11:"dailymotion";a:2:{i:0;a:3:{i:0;a:2:{i:0;s:27:"!dai\\.ly/(?\'id\'[a-z0-9]+)!i";i:1;a:2:{i:0;s:0:"";i:1;s:2:"id";}}i:1;a:2:{i:0;s:92:"!dailymotion\\.com/(?:live/|swf/|user/[^#]+#video=|(?:related/\\d+/)?video/)(?\'id\'[a-z0-9]+)!i";i:1;R:120;}i:2;a:2:{i:0;s:17:"!start=(?\'t\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:1:"t";}}}i:1;R:85;}s:8:"facebook";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:148:"@/(?!(?:apps|developers|graph)\\.)[-\\w.]*facebook\\.com/(?:[/\\w]+/permalink|(?!marketplace/|pages/|groups/).*?)(?:/|fbid=|\\?v=)(?\'id\'\\d+)(?=$|[/?&#])@";i:1;R:120;}i:1;a:2:{i:0;s:66:"@facebook\\.com/(?\'user\'[.\\w]+)/(?=(?:post|video)s?/)(?\'type\'[pv])@";i:1;a:3:{i:0;s:0:"";i:1;s:4:"user";i:2;s:4:"type";}}i:2;a:2:{i:0;s:49:"@facebook\\.com/video/(?=post|video)(?\'type\'[pv])@";i:1;a:2:{i:0;s:0:"";i:1;s:4:"type";}}i:3;a:2:{i:0;s:38:"@facebook\\.com/watch/\\?(?\'type\'[pv])=@";i:1;R:142;}}i:1;R:85;}s:8:"liveleak";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:41:"!liveleak\\.com/(?:e/|view\\?i=)(?\'id\'\\w+)!";i:1;R:120;}}i:1;a:1:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:28:"!liveleak\\.com/e/(?\'id\'\\w+)!";i:1;R:120;}}s:5:"match";a:1:{i:0;a:2:{i:0;s:24:"!liveleak\\.com/view\\?t=!";i:1;R:97;}}}}}s:10:"soundcloud";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:84:"@https?://(?:api\\.)?soundcloud\\.com/(?!pages/)(?\'id\'[-/\\w]+/[-/\\w]+|^[^/]+/[^/]+$)@i";i:1;R:120;}i:1;a:2:{i:0;s:52:"@api\\.soundcloud\\.com/playlists/(?\'playlist_id\'\\d+)@";i:1;a:2:{i:0;s:0:"";i:1;s:11:"playlist_id";}}i:2;a:2:{i:0;s:89:"@api\\.soundcloud\\.com/tracks/(?\'track_id\'\\d+)(?:\\?secret_token=(?\'secret_token\'[-\\w]+))?@";i:1;a:3:{i:0;s:0:"";i:1;s:8:"track_id";i:2;s:12:"secret_token";}}i:3;a:2:{i:0;s:81:"@soundcloud\\.com/(?!playlists|tracks)[-\\w]+/[-\\w]+/(?=s-)(?\'secret_token\'[-\\w]+)@";i:1;a:2:{i:0;s:0:"";i:1;s:12:"secret_token";}}}i:1;a:2:{i:0;a:3:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:36:"@soundcloud:tracks:(?\'track_id\'\\d+)@";i:1;R:110;}}s:6:"header";s:29:"User-agent: PHP (not Mozilla)";s:5:"match";a:1:{i:0;a:2:{i:0;s:56:"@soundcloud\\.com/(?!playlists/\\d|tracks/\\d)[-\\w]+/[-\\w]@";i:1;R:97;}}}i:1;a:3:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:44:"@soundcloud://playlists:(?\'playlist_id\'\\d+)@";i:1;R:165;}}s:6:"header";s:29:"User-agent: PHP (not Mozilla)";s:5:"match";a:1:{i:0;a:2:{i:0;s:27:"@soundcloud\\.com/\\w+/sets/@";i:1;R:97;}}}}}s:7:"spotify";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:115:"!(?:open|play)\\.spotify\\.com/(?:user/[-.\\w]+/)?(?\'id\'(?:album|artist|episode|playlist|show|track)(?:[:/][-.\\w]+)+)!";i:1;R:120;}}i:1;a:1:{i:0;a:3:{s:7:"extract";R:197;s:6:"header";s:29:"User-agent: PHP (not Mozilla)";s:5:"match";a:1:{i:0;a:2:{i:0;s:24:"!link\\.tospotify\\.com/.!";i:1;R:97;}}}}}s:6:"twitch";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:47:"#twitch\\.tv/(?:videos|\\w+/v)/(?\'video_id\'\\d+)?#";i:1;a:2:{i:0;s:0:"";i:1;s:8:"video_id";}}i:1;a:2:{i:0;s:70:"#www\\.twitch\\.tv/(?!videos/)(?\'channel\'\\w+)(?:/clip/(?\'clip_id\'\\w+))?#";i:1;a:3:{i:0;s:0:"";i:1;s:7:"channel";i:2;s:7:"clip_id";}}i:2;a:2:{i:0;s:32:"#t=(?\'t\'(?:(?:\\d+h)?\\d+m)?\\d+s)#";i:1;R:127;}i:3;a:2:{i:0;s:56:"#clips\\.twitch\\.tv/(?:(?\'channel\'\\w+)/)?(?\'clip_id\'\\w+)#";i:1;R:215;}}i:1;R:85;}s:5:"vimeo";a:2:{i:0;a:2:{i:0;a:2:{i:0;s:50:"!vimeo\\.com/(?:channels/[^/]+/|video/)?(?\'id\'\\d+)!";i:1;R:120;}i:1;a:2:{i:0;s:19:"!#t=(?\'t\'[\\dhms]+)!";i:1;R:127;}}i:1;R:85;}s:4:"vine";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:25:"!vine\\.co/v/(?\'id\'[^/]+)!";i:1;R:120;}}i:1;R:85;}s:7:"youtube";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:69:"!youtube\\.com/(?:watch.*?v=|v/|attribution_link.*?v%3D)(?\'id\'[-\\w]+)!";i:1;R:120;}i:1;a:2:{i:0;s:25:"!youtu\\.be/(?\'id\'[-\\w]+)!";i:1;R:120;}i:2;a:2:{i:0;s:25:"@[#&?]t=(?\'t\'\\d[\\dhms]*)@";i:1;R:127;}i:3;a:2:{i:0;s:26:"![&?]list=(?\'list\'[-\\w]+)!";i:1;a:2:{i:0;s:0:"";i:1;s:4:"list";}}}i:1;a:1:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:19:"!/vi/(?\'id\'[-\\w]+)!";i:1;R:120;}}s:5:"match";a:1:{i:0;a:2:{i:0;s:14:"!/shared\\?ci=!";i:1;R:97;}}}}}}}s:14:"' . "\0" . '*' . "\0" . 'rootContext";a:2:{s:7:"allowed";a:3:{i:0;i:65519;i:1;i:65329;i:2;i:257;}s:5:"flags";i:8;}s:13:"' . "\0" . '*' . "\0" . 'tagsConfig";a:77:{s:8:"BANDCAMP";a:7:{s:10:"attributes";a:3:{s:8:"album_id";a:2:{s:8:"required";b:0;s:11:"filterChain";R:85;}s:8:"track_id";R:263;s:9:"track_num";R:263;}s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:59:"s9e\\TextFormatter\\Parser\\FilterProcessing::filterAttributes";s:6:"params";a:4:{s:3:"tag";N;s:9:"tagConfig";N;s:14:"registeredVars";N;s:6:"logger";N;}}}s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3089;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";a:3:{i:0;i:32960;i:1;i:257;i:2;i:256;}}s:1:"C";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:66;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:0;i:1;i:0;i:2;i:0;}}s:4:"CODE";a:7:{s:10:"attributes";a:1:{s:4:"lang";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:23:"/^[- +,.0-9A-Za-z_]+$/D";}}}s:8:"required";b:0;}}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:10:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:4436;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:1;s:7:"allowed";R:288;}s:11:"DAILYMOTION";a:7:{s:10:"attributes";a:2:{s:2:"id";R:263;s:1:"t";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:3:"DEL";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:512;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";R:255;}s:2:"EM";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:2;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65477;i:1;i:65281;i:2;i:257;}}s:5:"EMAIL";a:7:{s:10:"attributes";a:1:{s:5:"email";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:61:"s9e\\TextFormatter\\Parser\\AttributeFilters\\EmailFilter::filter";s:6:"params";a:1:{s:9:"attrValue";N;}}}s:8:"required";b:1;}}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:514;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";a:3:{i:0;i:39819;i:1;i:65329;i:2;i:257;}}s:3:"ESC";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:1616;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:7;s:7:"allowed";R:288;}s:8:"FACEBOOK";a:7:{s:10:"attributes";a:3:{s:2:"id";R:263;s:4:"type";R:263;s:4:"user";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:2:"FP";a:7:{s:10:"attributes";a:1:{s:4:"char";a:2:{s:8:"required";b:1;s:11:"filterChain";R:85;}}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:8;s:7:"allowed";a:3:{i:0;i:32896;i:1;i:257;i:2;i:257;}}s:2:"H1";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:260;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:3;s:7:"allowed";R:335;}s:2:"H2";R:379;s:2:"H3";R:379;s:2:"H4";R:379;s:2:"H5";R:379;s:2:"H6";R:379;s:2:"HC";a:7:{s:10:"attributes";a:1:{s:7:"content";R:370;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3153;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:7;s:7:"allowed";R:288;}s:2:"HE";R:368;s:2:"HR";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:2:{s:11:"closeParent";R:304;s:5:"flags";i:3349;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";R:375;}s:3:"IMG";a:7:{s:10:"attributes";a:3:{s:3:"alt";R:263;s:3:"src";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:59:"s9e\\TextFormatter\\Parser\\AttributeFilters\\UrlFilter::filter";s:6:"params";a:3:{s:9:"attrValue";N;s:9:"urlConfig";N;s:6:"logger";N;}}}s:8:"required";b:1;}s:5:"title";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:375;}s:8:"ISPOILER";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:0;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";R:335;}s:2:"LI";a:7:{s:11:"filterChain";a:2:{i:0;R:266;i:1;a:2:{s:8:"callback";s:58:"s9e\\TextFormatter\\Plugins\\TaskLists\\Helper::filterListItem";s:6:"params";a:3:{s:6:"parser";N;s:3:"tag";N;s:4:"text";N;}}}s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"LI";i:1;s:7:"html:li";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:264;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:4;s:7:"allowed";a:3:{i:0;i:65519;i:1;i:65313;i:2;i:257;}}s:4:"LIST";a:7:{s:10:"attributes";a:2:{s:5:"start";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:67:"s9e\\TextFormatter\\Parser\\AttributeFilters\\NumericFilter::filterUint";s:6:"params";R:345;}}s:8:"required";b:0;}s:4:"type";R:294;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:3460;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65424;i:1;i:65280;i:2;i:257;}}s:8:"LIVELEAK";a:7:{s:10:"attributes";a:1:{s:2:"id";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:5:"MEDIA";a:7:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:54:"s9e\\TextFormatter\\Plugins\\MediaEmbed\\Parser::filterTag";s:6:"params";a:5:{s:3:"tag";N;s:6:"parser";N;s:16:"MediaEmbed.hosts";N;s:16:"MediaEmbed.sites";N;s:8:"cacheDir";N;}}}s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:513;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:16;s:7:"allowed";a:3:{i:0;i:65519;i:1;i:65329;i:2;i:256;}}s:5:"QUOTE";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:268;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";R:444;}s:10:"SOUNDCLOUD";a:7:{s:10:"attributes";a:4:{s:2:"id";R:263;s:11:"playlist_id";R:263;s:12:"secret_token";R:263;s:8:"track_id";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:7:"SPOILER";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:490;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:5;s:7:"allowed";R:444;}s:7:"SPOTIFY";R:464;s:6:"STRONG";R:329;s:3:"SUB";R:412;s:3:"SUP";R:412;s:5:"TABLE";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:456;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65290;i:2;i:257;}}s:4:"TASK";a:7:{s:10:"attributes";a:2:{s:2:"id";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:19:"/^[-0-9A-Za-z_]+$/D";}}}s:8:"required";b:1;}s:5:"state";R:513;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:375;}s:5:"TBODY";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:20:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:5:"TBODY";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:5:"THEAD";i:1;s:2:"TR";i:1;s:10:"html:tbody";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;s:10:"html:thead";i:1;s:7:"html:tr";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:9;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65288;i:2;i:257;}}s:2:"TD";a:7:{s:10:"attributes";a:1:{s:5:"align";a:2:{s:11:"filterChain";a:2:{i:0;a:2:{s:8:"callback";s:10:"strtolower";s:6:"params";R:345;}i:1;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:34:"/^(?:center|justify|left|right)$/D";}}}s:8:"required";b:0;}}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:14:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:444;}s:2:"TH";a:7:{s:10:"attributes";R:556;s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:568;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";a:3:{i:0;i:63463;i:1;i:65313;i:2;i:257;}}s:5:"THEAD";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:9;s:7:"allowed";R:551;}s:2:"TR";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:16:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:2:"TR";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;s:7:"html:tr";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:11;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65284;i:2;i:257;}}s:6:"TWITCH";a:7:{s:10:"attributes";a:4:{s:7:"channel";R:263;s:7:"clip_id";R:263;s:1:"t";R:263;s:8:"video_id";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:3:"URL";a:7:{s:10:"attributes";a:2:{s:5:"title";R:263;s:3:"url";R:400;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:349;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:353;}s:5:"VIMEO";a:7:{s:10:"attributes";a:2:{s:2:"id";R:263;s:1:"t";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:65:"s9e\\TextFormatter\\Parser\\AttributeFilters\\TimestampFilter::filter";s:6:"params";R:345;}}s:8:"required";b:0;}}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:4:"VINE";R:464;s:7:"YOUTUBE";a:7:{s:10:"attributes";a:3:{s:2:"id";a:2:{s:11:"filterChain";R:514;s:8:"required";b:0;}s:4:"list";R:263;s:1:"t";R:640;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:274;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:2;s:7:"allowed";R:278;}s:9:"html:abbr";a:7:{s:10:"attributes";a:1:{s:5:"title";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:414;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:335;}s:6:"html:b";R:329;s:7:"html:br";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3201;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65280;i:2;i:257;}}s:9:"html:code";R:282;s:7:"html:dd";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:dd";i:1;s:7:"html:dt";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:12;s:7:"allowed";R:444;}s:8:"html:del";R:323;s:8:"html:div";a:7:{s:10:"attributes";a:1:{s:5:"class";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:490;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:13;s:7:"allowed";R:255;}s:7:"html:dl";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:456;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65328;i:2;i:257;}}s:7:"html:dt";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:672;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:12;s:7:"allowed";R:591;}s:6:"html:i";R:329;s:8:"html:img";a:7:{s:10:"attributes";a:5:{s:3:"alt";R:263;s:6:"height";R:263;s:3:"src";a:2:{s:11:"filterChain";R:401;s:8:"required";b:0;}s:5:"title";R:263;s:5:"width";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:662;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:666;}s:8:"html:ins";R:323;s:7:"html:li";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:427;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:4;s:7:"allowed";R:444;}s:7:"html:ol";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:456;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";R:460;}s:8:"html:pre";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:276;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:1;s:7:"allowed";R:335;}s:7:"html:rb";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:13:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:rb";i:1;s:7:"html:rt";i:1;s:8:"html:rtc";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:14;s:7:"allowed";R:335;}s:7:"html:rp";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:304;s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:15;s:7:"allowed";R:335;}s:7:"html:rt";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:rb";i:1;s:7:"html:rt";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:15;s:7:"allowed";R:335;}s:8:"html:rtc";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:5:"EMAIL";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:rt";i:1;s:8:"html:rtc";i:1;}s:12:"fosterParent";R:304;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:14;s:7:"allowed";a:3:{i:0;i:65477;i:1;i:65409;i:2;i:257;}}s:9:"html:ruby";a:7:{s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:414;s:8:"tagLimit";i:5000;s:10:"attributes";R:85;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65477;i:1;i:65473;i:2;i:257;}}s:9:"html:span";a:7:{s:10:"attributes";R:690;s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:414;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:335;}s:11:"html:strong";R:329;s:8:"html:sub";R:412;s:8:"html:sup";R:412;s:10:"html:table";R:503;s:10:"html:tbody";R:524;s:7:"html:td";a:7:{s:10:"attributes";a:2:{s:7:"colspan";R:263;s:7:"rowspan";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:568;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:444;}s:10:"html:tfoot";R:524;s:7:"html:th";a:7:{s:10:"attributes";a:3:{s:7:"colspan";R:263;s:7:"rowspan";R:263;s:5:"scope";R:263;}s:11:"filterChain";R:265;s:12:"nestingLimit";i:10;s:5:"rules";R:568;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:591;}s:10:"html:thead";R:595;s:7:"html:tr";R:601;s:6:"html:u";R:329;s:7:"html:ul";R:717;}}');
	}
	public static function getRenderer()
	{
		return unserialize('O:42:"s9e\\TextFormatter\\Bundles\\Fatdown\\Renderer":2:{s:19:"enableQuickRenderer";b:1;s:9:"' . "\0" . '*' . "\0" . 'params";a:2:{s:16:"MEDIAEMBED_THEME";s:0:"";s:18:"TASKLISTS_EDITABLE";s:0:"";}}');
	}
}
namespace s9e\TextFormatter\Plugins\Autoemail;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		$tagName  = $this->config['tagName'];
		$attrName = $this->config['attrName'];

		foreach ($matches as $m)
		{
			$startTag = $this->parser->addStartTag($tagName, $m[0][1], 0);
			$startTag->setAttribute($attrName, $m[0][0]);
			$endTag = $this->parser->addEndTag($tagName, $m[0][1] + strlen($m[0][0]), 0);
			$startTag->pairWith($endTag);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Autolink;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		foreach ($matches as $m)
		{
			$this->linkifyUrl($m[0][1], $this->trimUrl($m[0][0]));
		}
	}
	protected function linkifyUrl($tagPos, $url)
	{
		$endPos = $tagPos + strlen($url);
		$endTag = $this->parser->addEndTag($this->config['tagName'], $endPos, 0);
		if ($url[3] === '.')
		{
			$url = 'http://' . $url;
		}
		$startTag = $this->parser->addStartTag($this->config['tagName'], $tagPos, 0, 1);
		$startTag->setAttribute($this->config['attrName'], $url);
		$startTag->pairWith($endTag);
		$contentTag = $this->parser->addVerbatim($tagPos, $endPos - $tagPos, 1000);
		$startTag->cascadeInvalidationTo($contentTag);
	}
	protected function trimUrl($url)
	{
		return preg_replace('#(?:(?![-=)/_])[\\s!-.:-@[-`{-~\\pP])+$#Du', '', $url);
	}
}
namespace s9e\TextFormatter\Plugins\Escaper;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		foreach ($matches as $m)
		{
			$this->parser->addTagPair(
				$this->config['tagName'],
				$m[0][1],
				1,
				$m[0][1] + strlen($m[0][0]),
				0
			);
		}
	}
}
namespace s9e\TextFormatter\Plugins\FancyPants;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	protected $hasDoubleQuote;
	protected $hasSingleQuote;
	protected $text;
	public function parse($text, array $matches)
	{
		$this->text           = $text;
		$this->hasSingleQuote = (strpos($text, "'") !== false);
		$this->hasDoubleQuote = (strpos($text, '"') !== false);

		if (empty($this->config['disableQuotes']))
		{
			$this->parseSingleQuotes();
			$this->parseSingleQuotePairs();
			$this->parseDoubleQuotePairs();
		}
		if (empty($this->config['disableGuillemets']))
		{
			$this->parseGuillemets();
		}
		if (empty($this->config['disableMathSymbols']))
		{
			$this->parseNotEqualSign();
			$this->parseSymbolsAfterDigits();
			$this->parseFractions();
		}
		if (empty($this->config['disablePunctuation']))
		{
			$this->parseDashesAndEllipses();
		}
		if (empty($this->config['disableSymbols']))
		{
			$this->parseSymbolsInParentheses();
		}

		unset($this->text);
	}
	protected function addTag($tagPos, $tagLen, $chr, $prio = 0)
	{
		$tag = $this->parser->addSelfClosingTag($this->config['tagName'], $tagPos, $tagLen, $prio);
		$tag->setAttribute($this->config['attrName'], $chr);

		return $tag;
	}
	protected function parseDashesAndEllipses()
	{
		if (strpos($this->text, '...') === false && strpos($this->text, '--') === false)
		{
			return;
		}

		$chrs = [
			'--'  => "\xE2\x80\x93",
			'---' => "\xE2\x80\x94",
			'...' => "\xE2\x80\xA6"
		];
		$regexp = '/---?|\\.\\.\\./S';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$this->addTag($m[1], strlen($m[0]), $chrs[$m[0]]);
		}
	}
	protected function parseDoubleQuotePairs()
	{
		if ($this->hasDoubleQuote)
		{
			$this->parseQuotePairs(
				'/(?<![0-9\\pL])"[^"\\n]+"(?![0-9\\pL])/uS',
				"\xE2\x80\x9C",
				"\xE2\x80\x9D"
			);
		}
	}
	protected function parseFractions()
	{
		if (strpos($this->text, '/') === false)
		{
			return;
		}

		$map = [
			'1/4'  => "\xC2\xBC",
			'1/2'  => "\xC2\xBD",
			'3/4'  => "\xC2\xBE",
			'1/7'  => "\xE2\x85\x90",
			'1/9'  => "\xE2\x85\x91",
			'1/10' => "\xE2\x85\x92",
			'1/3'  => "\xE2\x85\x93",
			'2/3'  => "\xE2\x85\x94",
			'1/5'  => "\xE2\x85\x95",
			'2/5'  => "\xE2\x85\x96",
			'3/5'  => "\xE2\x85\x97",
			'4/5'  => "\xE2\x85\x98",
			'1/6'  => "\xE2\x85\x99",
			'5/6'  => "\xE2\x85\x9A",
			'1/8'  => "\xE2\x85\x9B",
			'3/8'  => "\xE2\x85\x9C",
			'5/8'  => "\xE2\x85\x9D",
			'7/8'  => "\xE2\x85\x9E",
			'0/3'  => "\xE2\x86\x89"
		];

		$regexp = '/\\b(?:0\\/3|1\\/(?:[2-9]|10)|2\\/[35]|3\\/[458]|4\\/5|5\\/[68]|7\\/8)\\b/S';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$this->addTag($m[1], strlen($m[0]), $map[$m[0]]);
		}
	}
	protected function parseGuillemets()
	{
		if (strpos($this->text, '<<') === false)
		{
			return;
		}

		$regexp = '/<<( ?)(?! )[^\\n<>]*?[^\\n <>]\\1>>(?!>)/';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$left  = $this->addTag($m[1],                     2, "\xC2\xAB");
			$right = $this->addTag($m[1] + strlen($m[0]) - 2, 2, "\xC2\xBB");

			$left->cascadeInvalidationTo($right);
		}
	}
	protected function parseNotEqualSign()
	{
		if (strpos($this->text, '!=') === false && strpos($this->text, '=/=') === false)
		{
			return;
		}

		$regexp = '/\\b (?:!|=\\/)=(?= \\b)/';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$this->addTag($m[1] + 1, strlen($m[0]) - 1, "\xE2\x89\xA0");
		}
	}
	protected function parseQuotePairs($regexp, $leftQuote, $rightQuote)
	{
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$left  = $this->addTag($m[1], 1, $leftQuote);
			$right = $this->addTag($m[1] + strlen($m[0]) - 1, 1, $rightQuote);
			$left->cascadeInvalidationTo($right);
		}
	}
	protected function parseSingleQuotePairs()
	{
		if ($this->hasSingleQuote)
		{
			$this->parseQuotePairs(
				"/(?<![0-9\\pL])'[^'\\n]+'(?![0-9\\pL])/uS",
				"\xE2\x80\x98",
				"\xE2\x80\x99"
			);
		}
	}
	protected function parseSingleQuotes()
	{
		if (!$this->hasSingleQuote)
		{
			return;
		}

		$regexp = "/(?<=\\pL)'|(?<!\\S)'(?=\\pL|[0-9]{2})/uS";
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$this->addTag($m[1], 1, "\xE2\x80\x99", 10);
		}
	}
	protected function parseSymbolsAfterDigits()
	{
		if (!$this->hasSingleQuote && !$this->hasDoubleQuote && strpos($this->text, 'x') === false)
		{
			return;
		}

		$map = [
			"'s" => "\xE2\x80\x99",
			"'"  => "\xE2\x80\xB2",
			"' " => "\xE2\x80\xB2",
			"'x" => "\xE2\x80\xB2",
			'"'  => "\xE2\x80\xB3",
			'" ' => "\xE2\x80\xB3",
			'"x' => "\xE2\x80\xB3"
		];

		$regexp = "/[0-9](?>'s|[\"']? ?x(?= ?[0-9])|[\"'])/S";
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			if (substr($m[0], -1) === 'x')
			{
				$this->addTag($m[1] + strlen($m[0]) - 1, 1, "\xC3\x97");
			}
			$str = substr($m[0], 1, 2);
			if (isset($map[$str]))
			{
				$this->addTag($m[1] + 1, 1, $map[$str]);
			}
		}
	}
	protected function parseSymbolsInParentheses()
	{
		if (strpos($this->text, '(') === false)
		{
			return;
		}

		$chrs = [
			'(c)'  => "\xC2\xA9",
			'(r)'  => "\xC2\xAE",
			'(tm)' => "\xE2\x84\xA2"
		];
		$regexp = '/\\((?>c|r|tm)\\)/i';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as $m)
		{
			$this->addTag($m[1], strlen($m[0]), $chrs[strtr($m[0], 'CMRT', 'cmrt')]);
		}
	}
}
namespace s9e\TextFormatter\Plugins\HTMLComments;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		$tagName  = $this->config['tagName'];
		$attrName = $this->config['attrName'];

		foreach ($matches as $m)
		{
			$content = html_entity_decode(substr($m[0][0], 4, -3), ENT_QUOTES, 'UTF-8');
			$content = str_replace(['<', '>'], '', $content);
			$content = rtrim($content, '-');
			$content = str_replace('--', '', $content);

			$this->parser->addSelfClosingTag($tagName, $m[0][1], strlen($m[0][0]))->setAttribute($attrName, $content);
		}
	}
}
namespace s9e\TextFormatter\Plugins\HTMLElements;

use s9e\TextFormatter\Parser\Tag;
use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		foreach ($matches as $m)
		{
			$isEnd = (bool) ($text[$m[0][1] + 1] === '/');

			$pos    = $m[0][1];
			$len    = strlen($m[0][0]);
			$elName = strtolower($m[2 - $isEnd][0]);
			$tagName = (isset($this->config['aliases'][$elName]['']))
			         ? $this->config['aliases'][$elName]['']
			         : $this->config['prefix'] . ':' . $elName;

			if ($isEnd)
			{
				$this->parser->addEndTag($tagName, $pos, $len);
				continue;
			}
			$tag = (preg_match('/(<\\S+|[\'"\\s])\\/>$/', $m[0][0]))
			     ? $this->parser->addTagPair($tagName, $pos, $len, $pos + $len, 0)
			     : $this->parser->addStartTag($tagName, $pos, $len);

			$this->captureAttributes($tag, $elName, $m[3][0]);
		}
	}
	protected function captureAttributes(Tag $tag, $elName, $str)
	{
		$regexp = '/([a-z][-a-z0-9]*)(?>\\s*=\\s*("[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?/i';
		preg_match_all($regexp, $str, $matches, PREG_SET_ORDER);

		foreach ($matches as $m)
		{
			$attrName  = strtolower($m[1]);
			$attrValue = $m[2] ?? $attrName;
			if (isset($this->config['aliases'][$elName][$attrName]))
			{
				$attrName = $this->config['aliases'][$elName][$attrName];
			}
			if ($attrValue[0] === '"' || $attrValue[0] === "'")
			{
				$attrValue = substr($attrValue, 1, -1);
			}

			$tag->setAttribute($attrName, html_entity_decode($attrValue, ENT_QUOTES, 'UTF-8'));
		}
	}
}
namespace s9e\TextFormatter\Plugins\HTMLEntities;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		$tagName  = $this->config['tagName'];
		$attrName = $this->config['attrName'];

		foreach ($matches as $m)
		{
			$entity = $m[0][0];
			$chr    = html_entity_decode($entity, ENT_HTML5 | ENT_QUOTES, 'UTF-8');

			if ($chr === $entity || ord($chr) < 32)
			{
				continue;
			}

			$this->parser->addSelfClosingTag($tagName, $m[0][1], strlen($entity))->setAttribute($attrName, $chr);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown;

use s9e\TextFormatter\Parser\Tag;
use s9e\TextFormatter\Plugins\Litedown\Parser\ParsedText;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Blocks;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Emphasis;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\ForcedLineBreaks;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Images;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\InlineCode;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\InlineSpoiler;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\LinkReferences;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Links;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Strikethrough;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Subscript;
use s9e\TextFormatter\Plugins\Litedown\Parser\Passes\Superscript;
use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	public function parse($text, array $matches)
	{
		$text = new ParsedText($text);
		$text->decodeHtmlEntities = $this->config['decodeHtmlEntities'];
		(new Blocks($this->parser, $text))->parse();
		(new LinkReferences($this->parser, $text))->parse();
		(new InlineCode($this->parser, $text))->parse();
		(new Images($this->parser, $text))->parse();
		(new InlineSpoiler($this->parser, $text))->parse();
		(new Links($this->parser, $text))->parse();
		(new Strikethrough($this->parser, $text))->parse();
		(new Subscript($this->parser, $text))->parse();
		(new Superscript($this->parser, $text))->parse();
		(new Emphasis($this->parser, $text))->parse();
		(new ForcedLineBreaks($this->parser, $text))->parse();
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

abstract class AbstractInlineMarkup extends AbstractPass
{
	protected function parseInlineMarkup(string $str, string $regexp, string $tagName): void
	{
		$pos = $this->text->indexOf($str);
		if ($pos === false)
		{
			return;
		}

		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE, $pos);
		foreach ($matches[0] as [$match, $matchPos])
		{
			$matchLen = strlen($match);
			$endPos   = $matchPos + $matchLen - 2;

			$this->parser->addTagPair($tagName, $matchPos, 2, $endPos, 2);
			$this->text->overwrite($matchPos, 2);
			$this->text->overwrite($endPos, 2);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

abstract class AbstractScript extends AbstractPass
{
	protected $longRegexp;
	protected $shortRegexp;
	protected $syntaxChar;
	protected $tagName;
	protected function parseAbstractScript($tagName, $syntaxChar, $shortRegexp, $longRegexp)
	{
		$this->tagName     = $tagName;
		$this->syntaxChar  = $syntaxChar;
		$this->shortRegexp = $shortRegexp;
		$this->longRegexp  = $longRegexp;

		$pos = $this->text->indexOf($this->syntaxChar);
		if ($pos === false)
		{
			return;
		}

		$this->parseShortForm($pos);
		$this->parseLongForm($pos);
	}
	protected function parseLongForm($pos)
	{
		$pos = $this->text->indexOf($this->syntaxChar . '(', $pos);
		if ($pos === false)
		{
			return;
		}

		preg_match_all($this->longRegexp, $this->text, $matches, PREG_OFFSET_CAPTURE, $pos);
		foreach ($matches[0] as list($match, $matchPos))
		{
			$matchLen = strlen($match);

			$this->parser->addTagPair($this->tagName, $matchPos, 2, $matchPos + $matchLen - 1, 1);
			$this->text->overwrite($matchPos, $matchLen);
		}
		if (!empty($matches[0]))
		{
			$this->parseLongForm($pos);
		}
	}
	protected function parseShortForm($pos)
	{
		preg_match_all($this->shortRegexp, $this->text, $matches, PREG_OFFSET_CAPTURE, $pos);
		foreach ($matches[0] as list($match, $matchPos))
		{
			$matchLen = strlen($match);
			$startPos = $matchPos;
			$endLen   = (substr($match, -1) === $this->syntaxChar) ? 1 : 0;
			$endPos   = $matchPos + $matchLen - $endLen;

			$this->parser->addTagPair($this->tagName, $startPos, 1, $endPos, $endLen);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

use s9e\TextFormatter\Parser as Rules;

class Blocks extends AbstractPass
{
	protected $setextLines = [];
	public function parse()
	{
		$this->matchSetextLines();

		$blocks       = [];
		$blocksCnt    = 0;
		$codeFence    = null;
		$codeIndent   = 4;
		$codeTag      = null;
		$lineIsEmpty  = true;
		$lists        = [];
		$listsCnt     = 0;
		$newContext   = false;
		$textBoundary = 0;

		$regexp = '/^(?:(?=[-*+\\d \\t>`~#_])((?: {0,3}>(?:(?!!)|!(?![^\\n>]*?!<)) ?)+)?([ \\t]+)?(\\* *\\* *\\*[* ]*$|- *- *-[- ]*$|_ *_ *_[_ ]*$|=+$)?((?:[-*+]|\\d+\\.)[ \\t]+(?=\\S))?[ \\t]*(#{1,6}[ \\t]+|```+[^`\\n]*$|~~~+[^~\\n]*$)?)?/m';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

		foreach ($matches as $m)
		{
			$blockDepth = 0;
			$blockMarks = [];
			$ignoreLen  = 0;
			$matchLen   = strlen($m[0][0]);
			$matchPos   = $m[0][1];
			$continuation = !$lineIsEmpty;
			$lfPos       = $this->text->indexOf("\n", $matchPos);
			$lineIsEmpty = ($lfPos === $matchPos + $matchLen && empty($m[3][0]) && empty($m[4][0]) && empty($m[5][0]));
			$breakParagraph = ($lineIsEmpty && $continuation);
			if (!empty($m[1][0]))
			{
				$blockMarks = $this->getBlockMarks($m[1][0]);
				$blockDepth = count($blockMarks);
				$ignoreLen  = strlen($m[1][0]);
				if (isset($codeTag) && $codeTag->hasAttribute('blockDepth'))
				{
					$blockDepth = min($blockDepth, $codeTag->getAttribute('blockDepth'));
					$ignoreLen  = $this->computeBlockIgnoreLen($m[1][0], $blockDepth);
				}
				$this->text->overwrite($matchPos, $ignoreLen);
			}
			if ($blockDepth < $blocksCnt && !$continuation)
			{
				$newContext = true;
				do
				{
					$startTag = array_pop($blocks);
					$this->parser->addEndTag($startTag->getName(), $textBoundary, 0)
					             ->pairWith($startTag);
				}
				while ($blockDepth < --$blocksCnt);
			}
			if ($blockDepth > $blocksCnt && !$lineIsEmpty)
			{
				$newContext = true;
				do
				{
					$tagName  = ($blockMarks[$blocksCnt] === '>!') ? 'SPOILER' : 'QUOTE';
					$blocks[] = $this->parser->addStartTag($tagName, $matchPos, 0, -999);
				}
				while ($blockDepth > ++$blocksCnt);
			}
			$indentWidth = 0;
			$indentPos   = 0;
			if (!empty($m[2][0]) && !$codeFence)
			{
				$indentStr = $m[2][0];
				$indentLen = strlen($indentStr);
				do
				{
					if ($indentStr[$indentPos] === ' ')
					{
						++$indentWidth;
					}
					else
					{
						$indentWidth = ($indentWidth + 4) & ~3;
					}
				}
				while (++$indentPos < $indentLen && $indentWidth < $codeIndent);
			}
			if (isset($codeTag) && !$codeFence && $indentWidth < $codeIndent && !$lineIsEmpty)
			{
				$newContext = true;
			}

			if ($newContext)
			{
				$newContext = false;
				if (isset($codeTag))
				{
					if ($textBoundary > $codeTag->getPos())
					{
						$this->text->overwrite($codeTag->getPos(), $textBoundary - $codeTag->getPos());
						$codeTag->pairWith($this->parser->addEndTag('CODE', $textBoundary, 0, -1));
					}
					else
					{
						$codeTag->invalidate();
					}

					$codeTag = null;
					$codeFence = null;
				}
				foreach ($lists as $list)
				{
					$this->closeList($list, $textBoundary);
				}
				$lists    = [];
				$listsCnt = 0;
				if ($matchPos)
				{
					$this->text->markBoundary($matchPos - 1);
				}
			}

			if ($indentWidth >= $codeIndent)
			{
				if (isset($codeTag) || !$continuation)
				{
					$ignoreLen += $indentPos;

					if (!isset($codeTag))
					{
						$codeTag = $this->parser->addStartTag('CODE', $matchPos + $ignoreLen, 0, -999);
					}
					$m = [];
				}
			}
			else
			{
				$hasListItem = !empty($m[4][0]);

				if (!$indentWidth && !$continuation && !$hasListItem)
				{
					$listIndex = -1;
				}
				elseif ($continuation && !$hasListItem)
				{
					$listIndex = $listsCnt - 1;
				}
				elseif (!$listsCnt)
				{
					$listIndex = ($hasListItem) ? 0 : -1;
				}
				else
				{
					$listIndex = 0;
					while ($listIndex < $listsCnt && $indentWidth > $lists[$listIndex]['maxIndent'])
					{
						++$listIndex;
					}
				}
				while ($listIndex < $listsCnt - 1)
				{
					$this->closeList(array_pop($lists), $textBoundary);
					--$listsCnt;
				}
				if ($listIndex === $listsCnt && !$hasListItem)
				{
					--$listIndex;
				}

				if ($hasListItem && $listIndex >= 0)
				{
					$breakParagraph = true;
					$tagPos = $matchPos + $ignoreLen + $indentPos;
					$tagLen = strlen($m[4][0]);
					$itemTag = $this->parser->addStartTag('LI', $tagPos, $tagLen);
					$this->text->overwrite($tagPos, $tagLen);
					if ($listIndex < $listsCnt)
					{
						$this->parser->addEndTag('LI', $textBoundary, 0)
						             ->pairWith($lists[$listIndex]['itemTag']);
						$lists[$listIndex]['itemTag']    = $itemTag;
						$lists[$listIndex]['itemTags'][] = $itemTag;
					}
					else
					{
						++$listsCnt;

						if ($listIndex)
						{
							$minIndent = $lists[$listIndex - 1]['maxIndent'] + 1;
							$maxIndent = max($minIndent, $listIndex * 4);
						}
						else
						{
							$minIndent = 0;
							$maxIndent = $indentWidth;
						}
						$listTag = $this->parser->addStartTag('LIST', $tagPos, 0);
						if (strpos($m[4][0], '.') !== false)
						{
							$listTag->setAttribute('type', 'decimal');

							$start = (int) $m[4][0];
							if ($start !== 1)
							{
								$listTag->setAttribute('start', $start);
							}
						}
						$lists[] = [
							'listTag'   => $listTag,
							'itemTag'   => $itemTag,
							'itemTags'  => [$itemTag],
							'minIndent' => $minIndent,
							'maxIndent' => $maxIndent,
							'tight'     => true
						];
					}
				}
				if ($listsCnt && !$continuation && !$lineIsEmpty)
				{
					if (count($lists[0]['itemTags']) > 1 || !$hasListItem)
					{
						foreach ($lists as &$list)
						{
							$list['tight'] = false;
						}
						unset($list);
					}
				}

				$codeIndent = ($listsCnt + 1) * 4;
			}

			if (isset($m[5]))
			{
				if ($m[5][0][0] === '#')
				{
					$startLen = strlen($m[5][0]);
					$startPos = $matchPos + $matchLen - $startLen;
					$endLen   = $this->getAtxHeaderEndTagLen($matchPos + $matchLen, $lfPos);
					$endPos   = $lfPos - $endLen;

					$this->parser->addTagPair('H' . strspn($m[5][0], '#', 0, 6), $startPos, $startLen, $endPos, $endLen);
					$this->text->markBoundary($startPos);
					$this->text->markBoundary($lfPos);

					if ($continuation)
					{
						$breakParagraph = true;
					}
				}
				elseif ($m[5][0][0] === '`' || $m[5][0][0] === '~')
				{
					$tagPos = $matchPos + $ignoreLen;
					$tagLen = $lfPos - $tagPos;

					if (isset($codeTag) && $m[5][0] === $codeFence)
					{
						$codeTag->pairWith($this->parser->addEndTag('CODE', $tagPos, $tagLen, -1));
						$this->parser->addIgnoreTag($textBoundary, $tagPos - $textBoundary);
						$this->text->overwrite($codeTag->getPos(), $tagPos + $tagLen - $codeTag->getPos());
						$codeTag = null;
						$codeFence = null;
					}
					elseif (!isset($codeTag))
					{
						$codeTag   = $this->parser->addStartTag('CODE', $tagPos, $tagLen);
						$codeFence = substr($m[5][0], 0, strspn($m[5][0], '`~'));
						$codeTag->setAttribute('blockDepth', $blockDepth);
						$this->parser->addIgnoreTag($tagPos + $tagLen, 1);
						$lang = trim(trim($m[5][0], '`~'));
						if ($lang !== '')
						{
							$codeTag->setAttribute('lang', $lang);
						}
					}
				}
			}
			elseif (!empty($m[3][0]) && !$listsCnt && $this->text->charAt($matchPos + $matchLen) !== "\x17")
			{
				$this->parser->addSelfClosingTag('HR', $matchPos + $ignoreLen, $matchLen - $ignoreLen);
				$breakParagraph = true;
				$this->text->markBoundary($lfPos);
			}
			elseif (isset($this->setextLines[$lfPos]) && $this->setextLines[$lfPos]['blockDepth'] === $blockDepth && !$lineIsEmpty && !$listsCnt && !isset($codeTag))
			{
				$this->parser->addTagPair(
					$this->setextLines[$lfPos]['tagName'],
					$matchPos + $ignoreLen,
					0,
					$this->setextLines[$lfPos]['endPos'],
					$this->setextLines[$lfPos]['endLen']
				);
				$this->text->markBoundary($this->setextLines[$lfPos]['endPos'] + $this->setextLines[$lfPos]['endLen']);
			}

			if ($breakParagraph)
			{
				$this->parser->addParagraphBreak($textBoundary);
				$this->text->markBoundary($textBoundary);
			}

			if (!$lineIsEmpty)
			{
				$textBoundary = $lfPos;
			}

			if ($ignoreLen)
			{
				$this->parser->addIgnoreTag($matchPos, $ignoreLen, 1000);
			}
		}
	}
	protected function closeList(array $list, $textBoundary)
	{
		$this->parser->addEndTag('LIST', $textBoundary, 0)->pairWith($list['listTag']);
		$this->parser->addEndTag('LI',   $textBoundary, 0)->pairWith($list['itemTag']);

		if ($list['tight'])
		{
			foreach ($list['itemTags'] as $itemTag)
			{
				$itemTag->removeFlags(Rules::RULE_CREATE_PARAGRAPHS);
			}
		}
	}
	protected function computeBlockIgnoreLen($str, $maxBlockDepth)
	{
		$remaining = $str;
		while (--$maxBlockDepth >= 0)
		{
			$remaining = preg_replace('/^ *>!? ?/', '', $remaining);
		}

		return strlen($str) - strlen($remaining);
	}
	protected function getAtxHeaderEndTagLen($startPos, $endPos)
	{
		$content = substr($this->text, $startPos, $endPos - $startPos);
		preg_match('/[ \\t]*#*[ \\t]*$/', $content, $m);

		return strlen($m[0]);
	}
	protected function getBlockMarks($str)
	{
		preg_match_all('(>!?)', $str, $m);

		return $m[0];
	}
	protected function matchSetextLines()
	{
		if ($this->text->indexOf('-') === false && $this->text->indexOf('=') === false)
		{
			return;
		}
		$regexp = '/^(?=[-=>])(?:>!? ?)*(?=[-=])(?:-+|=+) *$/m';
		if (!preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE))
		{
			return;
		}

		foreach ($matches[0] as list($match, $matchPos))
		{
			$endPos = $matchPos - 1;
			while ($endPos > 0 && $this->text->charAt($endPos - 1) === ' ')
			{
				--$endPos;
			}
			$this->setextLines[$matchPos - 1] = [
				'endLen'     => $matchPos + strlen($match) - $endPos,
				'endPos'     => $endPos,
				'blockDepth' => substr_count($match, '>'),
				'tagName'    => ($match[0] === '=') ? 'H1' : 'H2'
			];
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class Emphasis extends AbstractPass
{
	protected $closeEm;
	protected $closeStrong;
	protected $emPos;
	protected $emEndPos;
	protected $remaining;
	protected $strongPos;
	protected $strongEndPos;
	public function parse()
	{
		$this->parseEmphasisByCharacter('*', '/\\*+/');
		$this->parseEmphasisByCharacter('_', '/_+/');
	}
	protected function adjustEndingPositions()
	{
		if ($this->closeEm && $this->closeStrong)
		{
			if ($this->emPos < $this->strongPos)
			{
				$this->emEndPos += 2;
			}
			else
			{
				++$this->strongEndPos;
			}
		}
	}
	protected function adjustStartingPositions()
	{
		if ($this->emPos >= 0 && $this->emPos === $this->strongPos)
		{
			if ($this->closeEm)
			{
				$this->emPos += 2;
			}
			else
			{
				++$this->strongPos;
			}
		}
	}
	protected function closeSpans()
	{
		if ($this->closeEm)
		{
			--$this->remaining;
			$this->parser->addTagPair('EM', $this->emPos, 1, $this->emEndPos, 1);
			$this->emPos = -1;
		}
		if ($this->closeStrong)
		{
			$this->remaining -= 2;
			$this->parser->addTagPair('STRONG', $this->strongPos, 2, $this->strongEndPos, 2);
			$this->strongPos = -1;
		}
	}
	protected function parseEmphasisByCharacter($character, $regexp)
	{
		$pos = $this->text->indexOf($character);
		if ($pos === false)
		{
			return;
		}

		foreach ($this->getEmphasisByBlock($regexp, $pos) as $block)
		{
			$this->processEmphasisBlock($block);
		}
	}
	protected function getEmphasisByBlock($regexp, $pos)
	{
		$block    = [];
		$blocks   = [];
		$breakPos = $this->text->indexOf("\x17", $pos);

		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE, $pos);
		foreach ($matches[0] as $m)
		{
			$matchPos = $m[1];
			$matchLen = strlen($m[0]);
			if ($matchPos > $breakPos)
			{
				$blocks[] = $block;
				$block    = [];
				$breakPos = $this->text->indexOf("\x17", $matchPos);
			}
			if (!$this->ignoreEmphasis($matchPos, $matchLen))
			{
				$block[] = [$matchPos, $matchLen];
			}
		}
		$blocks[] = $block;

		return $blocks;
	}
	protected function ignoreEmphasis($matchPos, $matchLen)
	{
		return ($this->text->charAt($matchPos) === '_' && $matchLen === 1 && $this->text->isSurroundedByAlnum($matchPos, $matchLen));
	}
	protected function openSpans($pos)
	{
		if ($this->remaining & 1)
		{
			$this->emPos     = $pos - $this->remaining;
		}
		if ($this->remaining & 2)
		{
			$this->strongPos = $pos - $this->remaining;
		}
	}
	protected function processEmphasisBlock(array $block)
	{
		$this->emPos     = -1;
		$this->strongPos = -1;
		foreach ($block as list($matchPos, $matchLen))
		{
			$this->processEmphasisMatch($matchPos, $matchLen);
		}
	}
	protected function processEmphasisMatch($matchPos, $matchLen)
	{
		$canOpen  = !$this->text->isBeforeWhitespace($matchPos + $matchLen - 1);
		$canClose = !$this->text->isAfterWhitespace($matchPos);
		$closeLen = ($canClose) ? min($matchLen, 3) : 0;

		$this->closeEm      = ($closeLen & 1) && $this->emPos     >= 0;
		$this->closeStrong  = ($closeLen & 2) && $this->strongPos >= 0;
		$this->emEndPos     = $matchPos;
		$this->strongEndPos = $matchPos;
		$this->remaining    = $matchLen;

		$this->adjustStartingPositions();
		$this->adjustEndingPositions();
		$this->closeSpans();
		$this->remaining = ($canOpen) ? min($this->remaining, 3) : 0;
		$this->openSpans($matchPos + $matchLen);
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class ForcedLineBreaks extends AbstractPass
{
	public function parse()
	{
		$pos = $this->text->indexOf("  \n");
		while ($pos !== false)
		{
			$this->parser->addBrTag($pos + 2)->cascadeInvalidationTo(
				$this->parser->addVerbatim($pos + 2, 1)
			);
			$pos = $this->text->indexOf("  \n", $pos + 3);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

use s9e\TextFormatter\Plugins\Litedown\Parser\LinkAttributesSetter;

class Images extends AbstractPass
{
	use LinkAttributesSetter;
	public function parse()
	{
		$pos = $this->text->indexOf('![');
		if ($pos === false)
		{
			return;
		}
		if ($this->text->indexOf('](', $pos) !== false)
		{
			$this->parseInlineImages();
		}
		if ($this->text->hasReferences)
		{
			$this->parseReferenceImages();
		}
	}
	protected function addImageTag($startPos, $endPos, $endLen, $linkInfo, $alt)
	{
		$tag = $this->parser->addTagPair('IMG', $startPos, 2, $endPos, $endLen);
		$this->setLinkAttributes($tag, $linkInfo, 'src');
		$tag->setAttribute('alt', $this->text->decode($alt));
		$this->text->overwrite($startPos, $endPos + $endLen - $startPos);
	}
	protected function parseInlineImages()
	{
		preg_match_all(
			'/!\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:[^\\x17\\s()]|\\([^\\x17\\s()]*\\))*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/',
			$this->text,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		foreach ($matches as $m)
		{
			$linkInfo = $m[1][0];
			$startPos = $m[0][1];
			$endLen   = 3 + strlen($linkInfo);
			$endPos   = $startPos + strlen($m[0][0]) - $endLen;
			$alt      = substr($m[0][0], 2, strlen($m[0][0]) - $endLen - 2);

			$this->addImageTag($startPos, $endPos, $endLen, $linkInfo, $alt);
		}
	}
	protected function parseReferenceImages()
	{
		preg_match_all(
			'/!\\[((?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*)\\](?: ?\\[([^\\x17[\\]]+)\\])?/',
			$this->text,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		foreach ($matches as $m)
		{
			$startPos = $m[0][1];
			$endPos   = $startPos + 2 + strlen($m[1][0]);
			$endLen   = 1;
			$alt      = $m[1][0];
			$id       = $alt;

			if (isset($m[2][0], $this->text->linkReferences[$m[2][0]]))
			{
				$endLen = strlen($m[0][0]) - strlen($alt) - 2;
				$id        = $m[2][0];
			}
			elseif (!isset($this->text->linkReferences[$id]))
			{
				continue;
			}

			$this->addImageTag($startPos, $endPos, $endLen, $this->text->linkReferences[$id], $alt);
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class InlineCode extends AbstractPass
{
	public function parse()
	{
		$markers = $this->getInlineCodeMarkers();
		$i       = -1;
		$cnt     = count($markers);
		while (++$i < ($cnt - 1))
		{
			$pos = $markers[$i]['next'];
			$j   = $i;
			if ($this->text->charAt($markers[$i]['pos']) !== '`')
			{
				++$markers[$i]['pos'];
				--$markers[$i]['len'];
			}
			while (++$j < $cnt && $markers[$j]['pos'] === $pos)
			{
				if ($markers[$j]['len'] === $markers[$i]['len'])
				{
					$this->addInlineCodeTags($markers[$i], $markers[$j]);
					$i = $j;
					break;
				}
				$pos = $markers[$j]['next'];
			}
		}
	}
	protected function addInlineCodeTags($left, $right)
	{
		$startPos = $left['pos'];
		$startLen = $left['len'] + $left['trimAfter'];
		$endPos   = $right['pos'] - $right['trimBefore'];
		$endLen   = $right['len'] + $right['trimBefore'];
		$this->parser->addTagPair('C', $startPos, $startLen, $endPos, $endLen);
		$this->text->overwrite($startPos, $endPos + $endLen - $startPos);
	}
	protected function getInlineCodeMarkers()
	{
		$pos = $this->text->indexOf('`');
		if ($pos === false)
		{
			return [];
		}

		preg_match_all(
			'/(`+)(\\s*)[^\\x17`]*/',
			str_replace("\x1BD", '\\`', $this->text),
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER,
			$pos
		);
		$trimNext = 0;
		$markers  = [];
		foreach ($matches as $m)
		{
			$markers[] = [
				'pos'        => $m[0][1],
				'len'        => strlen($m[1][0]),
				'trimBefore' => $trimNext,
				'trimAfter'  => strlen($m[2][0]),
				'next'       => $m[0][1] + strlen($m[0][0])
			];
			$trimNext = strlen($m[0][0]) - strlen(rtrim($m[0][0]));
		}

		return $markers;
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class LinkReferences extends AbstractPass
{
	public function parse()
	{
		if ($this->text->indexOf(']:') === false)
		{
			return;
		}

		$regexp = '/^\\x1A* {0,3}\\[([^\\x17\\]]+)\\]: *([^[\\s\\x17]+ *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))?) *(?=$|\\x17)\\n?/m';
		preg_match_all($regexp, $this->text, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
		foreach ($matches as $m)
		{
			$this->parser->addIgnoreTag($m[0][1], strlen($m[0][0]));
			$id = strtolower($m[1][0]);
			if (!isset($this->text->linkReferences[$id]))
			{
				$this->text->hasReferences       = true;
				$this->text->linkReferences[$id] = $m[2][0];
			}
		}
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

use s9e\TextFormatter\Plugins\Litedown\Parser\LinkAttributesSetter;

class Links extends AbstractPass
{
	use LinkAttributesSetter;
	public function parse()
	{
		if ($this->text->indexOf('](') !== false)
		{
			$this->parseInlineLinks();
		}
		if ($this->text->indexOf('<') !== false)
		{
			$this->parseAutomaticLinks();
		}
		if ($this->text->hasReferences)
		{
			$this->parseReferenceLinks();
		}
	}
	protected function addLinkTag($startPos, $endPos, $endLen, $linkInfo)
	{
		$priority = ($endLen === 1) ? 1 : -1;

		$tag = $this->parser->addTagPair('URL', $startPos, 1, $endPos, $endLen, $priority);
		$this->setLinkAttributes($tag, $linkInfo, 'url');
		$this->text->overwrite($startPos, 1);
		$this->text->overwrite($endPos,   $endLen);
	}
	protected function getLabels()
	{
		preg_match_all(
			'/\\[((?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*)\\]/',
			$this->text,
			$matches,
			PREG_OFFSET_CAPTURE
		);
		$labels = [];
		foreach ($matches[1] as $m)
		{
			$labels[$m[1] - 1] = strtolower($m[0]);
		}

		return $labels;
	}
	protected function parseAutomaticLinks()
	{
		preg_match_all(
			'/<[-+.\\w]++([:@])[^\\x17\\s>]+?(?:>|\\x1B7)/',
			$this->text,
			$matches,
			PREG_OFFSET_CAPTURE
		);
		foreach ($matches[0] as $i => $m)
		{
			$content  = substr($this->text->decode(str_replace("\x1B", "\\\x1B", $m[0])), 1, -1);
			$startPos = $m[1];
			$endPos   = $startPos + strlen($m[0]) - 1;

			$tagName  = ($matches[1][$i][0] === ':') ? 'URL' : 'EMAIL';
			$attrName = strtolower($tagName);

			$this->parser->addTagPair($tagName, $startPos, 1, $endPos, 1)
			             ->setAttribute($attrName, $content);
		}
	}
	protected function parseInlineLinks()
	{
		preg_match_all(
			'/\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:\\([^\\x17\\s()]*\\)|[^\\x17\\s)])*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/',
			$this->text,
			$matches,
			PREG_OFFSET_CAPTURE | PREG_SET_ORDER
		);
		foreach ($matches as $m)
		{
			$linkInfo = $m[1][0];
			$startPos = $m[0][1];
			$endLen   = 3 + strlen($linkInfo);
			$endPos   = $startPos + strlen($m[0][0]) - $endLen;

			$this->addLinkTag($startPos, $endPos, $endLen, $linkInfo);
		}
	}
	protected function parseReferenceLinks()
	{
		$labels = $this->getLabels();
		foreach ($labels as $startPos => $id)
		{
			$labelPos = $startPos + 2 + strlen($id);
			$endPos   = $labelPos - 1;
			$endLen   = 1;

			if ($this->text->charAt($labelPos) === ' ')
			{
				++$labelPos;
			}
			if (isset($labels[$labelPos], $this->text->linkReferences[$labels[$labelPos]]))
			{
				$id     = $labels[$labelPos];
				$endLen = $labelPos + 2 + strlen($id) - $endPos;
			}
			if (isset($this->text->linkReferences[$id]))
			{
				$this->addLinkTag($startPos, $endPos, $endLen, $this->text->linkReferences[$id]);
			}
		}
	}
}
namespace s9e\TextFormatter\Plugins\MediaEmbed;

use s9e\TextFormatter\Parser as TagStack;
use s9e\TextFormatter\Parser\Tag;
use s9e\TextFormatter\Plugins\ParserBase;
use s9e\TextFormatter\Utils\Http;

class Parser extends ParserBase
{
	protected static $client;
	protected static $clientCacheDir;
	public function parse($text, array $matches)
	{
		foreach ($matches as $m)
		{
			$tagName = $this->config['tagName'];
			$url     = $m[0][0];
			$pos     = $m[0][1];
			$len     = strlen($url);
			$this->parser->addSelfClosingTag($tagName, $pos, $len, -10)->setAttribute('url', $url);
		}
	}
	public static function filterTag(Tag $tag, TagStack $tagStack, array $hosts, array $sites, $cacheDir)
	{
		$tag->invalidate();

		if ($tag->hasAttribute('url'))
		{
			$url    = $tag->getAttribute('url');
			$siteId = self::getSiteIdFromUrl($url, $hosts);
			if (isset($sites[$siteId]))
			{
				$attributes = self::getAttributes($url, $sites[$siteId], $cacheDir);
				if (!empty($attributes))
				{
					self::createTag(strtoupper($siteId), $tagStack, $tag)->setAttributes($attributes);
				}
			}
		}
	}
	protected static function addNamedCaptures(array &$attributes, $string, array $regexps)
	{
		$matched = 0;
		foreach ($regexps as list($regexp, $map))
		{
			$matched += preg_match($regexp, $string, $m);
			foreach ($map as $i => $name)
			{
				if (isset($m[$i]) && $m[$i] !== '' && $name !== '')
				{
					$attributes[$name] = $m[$i];
				}
			}
		}

		return (bool) $matched;
	}
	protected static function createTag($tagName, TagStack $tagStack, Tag $tag)
	{
		$startPos = $tag->getPos();
		$endTag   = $tag->getEndTag();
		if ($endTag)
		{
			$startLen = $tag->getLen();
			$endPos   = $endTag->getPos();
			$endLen   = $endTag->getLen();
		}
		else
		{
			$startLen = 0;
			$endPos   = $tag->getPos() + $tag->getLen();
			$endLen   = 0;
		}

		return $tagStack->addTagPair($tagName, $startPos, $startLen, $endPos, $endLen, $tag->getSortPriority());
	}
	protected static function getAttributes($url, array $config, $cacheDir)
	{
		$attributes = [];
		self::addNamedCaptures($attributes, $url, $config[0]);
		foreach ($config[1] as $scrapeConfig)
		{
			self::scrape($attributes, $url, $scrapeConfig, $cacheDir);
		}

		return $attributes;
	}
	protected static function getHttpClient($cacheDir)
	{
		if (!isset(self::$client) || self::$clientCacheDir !== $cacheDir)
		{
			self::$client = (isset($cacheDir)) ? Http::getCachingClient($cacheDir) : Http::getClient();
			self::$clientCacheDir = $cacheDir;
		}

		return self::$client;
	}
	protected static function getSiteIdFromUrl($url, array $hosts)
	{
		$host = (preg_match('(^https?://([^/]+))', strtolower($url), $m)) ? $m[1] : '';
		while ($host > '')
		{
			if (isset($hosts[$host]))
			{
				return $hosts[$host];
			}
			$host = preg_replace('(^[^.]*.)', '', $host);
		}

		return '';
	}
	protected static function interpolateVars($str, array $vars)
	{
		return preg_replace_callback(
			'(\\{@(\\w+)\\})',
			function ($m) use ($vars)
			{
				return (isset($vars[$m[1]])) ? $vars[$m[1]] : '';
			},
			$str
		);
	}
	protected static function scrape(array &$attributes, $url, array $config, $cacheDir)
	{
		$vars = [];
		if (self::addNamedCaptures($vars, $url, $config['match']))
		{
			if (isset($config['url']))
			{
				$url = self::interpolateVars($config['url'], $vars + $attributes);
			}
			if (preg_match('(^https?://[^#]+)i', $url, $m))
			{
				$response = self::wget($m[0], $cacheDir, $config);
				self::addNamedCaptures($attributes, $response, $config['extract']);
			}
		}
	}
	protected static function wget($url, $cacheDir, $config)
	{
		$options = [
			'headers' => (isset($config['header'])) ? (array) $config['header'] : []
		];

		return @self::getHttpClient($cacheDir)->get($url, $options);
	}
}
namespace s9e\TextFormatter\Plugins\PipeTables;

use s9e\TextFormatter\Plugins\ParserBase;

class Parser extends ParserBase
{
	protected $pos;
	protected $table;
	protected $tableTag;
	protected $tables;
	protected $text;
	public function parse($text, array $matches)
	{
		$this->text = $text;
		if ($this->config['overwriteMarkdown'])
		{
			$this->overwriteMarkdown();
		}
		if ($this->config['overwriteEscapes'])
		{
			$this->overwriteEscapes();
		}

		$this->captureTables();
		$this->processTables();

		unset($this->tables);
		unset($this->text);
	}
	protected function addLine($line)
	{
		$ignoreLen = 0;

		if (!isset($this->table))
		{
			$this->table = [];
			preg_match('/^ */', $line, $m);
			$ignoreLen = strlen($m[0]);
			$line      = substr($line, $ignoreLen);
		}
		$line = preg_replace('/^( *)\\|/', '$1 ', $line);
		$line = preg_replace('/\\|( *)$/', ' $1', $line);

		$this->table['rows'][] = ['line' => $line, 'pos' => $this->pos + $ignoreLen];
	}
	protected function addTableBody()
	{
		$i   = 1;
		$cnt = count($this->table['rows']);
		while (++$i < $cnt)
		{
			$this->addTableRow('TD', $this->table['rows'][$i]);
		}

		$this->createBodyTags($this->table['rows'][2]['pos'], $this->pos);
	}
	protected function addTableCell($tagName, $align, $content)
	{
		$startPos  = $this->pos;
		$endPos    = $startPos + strlen($content);
		$this->pos = $endPos;

		preg_match('/^( *).*?( *)$/', $content, $m);
		if ($m[1])
		{
			$ignoreLen = strlen($m[1]);
			$this->createIgnoreTag($startPos, $ignoreLen);
			$startPos += $ignoreLen;
		}
		if ($m[2])
		{
			$ignoreLen = strlen($m[2]);
			$this->createIgnoreTag($endPos - $ignoreLen, $ignoreLen);
			$endPos -= $ignoreLen;
		}

		$this->createCellTags($tagName, $startPos, $endPos, $align);
	}
	protected function addTableHead()
	{
		$this->addTableRow('TH', $this->table['rows'][0]);
		$this->createHeadTags($this->table['rows'][0]['pos'], $this->pos);
	}
	protected function addTableRow($tagName, $row)
	{
		$this->pos = $row['pos'];
		foreach (explode('|', $row['line']) as $i => $str)
		{
			if ($i > 0)
			{
				$this->createIgnoreTag($this->pos, 1);
				++$this->pos;
			}

			$align = (empty($this->table['cols'][$i])) ? '' : $this->table['cols'][$i];
			$this->addTableCell($tagName, $align, $str);
		}

		$this->createRowTags($row['pos'], $this->pos);
	}
	protected function captureTables()
	{
		unset($this->table);
		$this->tables = [];

		$this->pos = 0;
		foreach (explode("\n", $this->text) as $line)
		{
			if (strpos($line, '|') === false)
			{
				$this->endTable();
			}
			else
			{
				$this->addLine($line);
			}
			$this->pos += 1 + strlen($line);
		}
		$this->endTable();
	}
	protected function createBodyTags($startPos, $endPos)
	{
		$this->parser->addTagPair('TBODY', $startPos, 0, $endPos, 0, -103);
	}
	protected function createCellTags($tagName, $startPos, $endPos, $align)
	{
		if ($startPos === $endPos)
		{
			$tag = $this->parser->addSelfClosingTag($tagName, $startPos, 0, -101);
		}
		else
		{
			$tag = $this->parser->addTagPair($tagName, $startPos, 0, $endPos, 0, -101);
		}
		if ($align)
		{
			$tag->setAttribute('align', $align);
		}
	}
	protected function createHeadTags($startPos, $endPos)
	{
		$this->parser->addTagPair('THEAD', $startPos, 0, $endPos, 0, -103);
	}
	protected function createIgnoreTag($pos, $len)
	{
		$this->tableTag->cascadeInvalidationTo($this->parser->addIgnoreTag($pos, $len, 1000));
	}
	protected function createRowTags($startPos, $endPos)
	{
		$this->parser->addTagPair('TR', $startPos, 0, $endPos, 0, -102);
	}
	protected function createSeparatorTag(array $row)
	{
		$this->createIgnoreTag($row['pos'] - 1, 1 + strlen($row['line']));
	}
	protected function createTableTags($startPos, $endPos)
	{
		$this->tableTag = $this->parser->addTagPair('TABLE', $startPos, 0, $endPos, 0, -104);
	}
	protected function endTable()
	{
		if ($this->hasValidTable())
		{
			$this->table['cols'] = $this->parseColumnAlignments($this->table['rows'][1]['line']);
			$this->tables[]      = $this->table;
		}
		unset($this->table);
	}
	protected function hasValidTable()
	{
		return (isset($this->table) && count($this->table['rows']) > 2 && $this->isValidSeparator($this->table['rows'][1]['line']));
	}
	protected function isValidSeparator($line)
	{
		return (bool) preg_match('/^ *:?-+:?(?:(?:\\+| *\\| *):?-+:?)+ *$/', $line);
	}
	protected function overwriteBlockquoteCallback(array $m)
	{
		return strtr($m[0], '!>', '  ');
	}
	protected function overwriteEscapes()
	{
		if (strpos($this->text, '\\|') !== false)
		{
			$this->text = preg_replace('/\\\\[\\\\|]/', '..', $this->text);
		}
	}
	protected function overwriteInlineCodeCallback(array $m)
	{
		return strtr($m[0], '|', '.');
	}
	protected function overwriteMarkdown()
	{
		if (strpos($this->text, '`') !== false)
		{
			$this->text = preg_replace_callback('/`[^`]*`/', [$this, 'overwriteInlineCodeCallback'], $this->text);
		}
		if (strpos($this->text, '>') !== false)
		{
			$this->text = preg_replace_callback('/^(?:>!? ?)+/m', [$this, 'overwriteBlockquoteCallback'], $this->text);
		}
	}
	protected function parseColumnAlignments($line)
	{
		$align = [
			0b00 => '',
			0b01 => 'right',
			0b10 => 'left',
			0b11 => 'center'
		];

		$cols = [];
		preg_match_all('/(:?)-+(:?)/', $line, $matches, PREG_SET_ORDER);
		foreach ($matches as $m)
		{
			$key = (!empty($m[1]) ? 2 : 0) + (!empty($m[2]) ? 1 : 0);
			$cols[] = $align[$key];
		}

		return $cols;
	}
	protected function processCurrentTable()
	{
		$firstRow = $this->table['rows'][0];
		$lastRow  = end($this->table['rows']);
		$this->createTableTags($firstRow['pos'], $lastRow['pos'] + strlen($lastRow['line']));

		$this->addTableHead();
		$this->createSeparatorTag($this->table['rows'][1]);
		$this->addTableBody();
	}
	protected function processTables()
	{
		foreach ($this->tables as $table)
		{
			$this->table = $table;
			$this->processCurrentTable();
		}
	}
}
namespace s9e\TextFormatter\Renderers;

use DOMNode;
use DOMXPath;
use RuntimeException;
use s9e\TextFormatter\Renderer;
use s9e\TextFormatter\Utils\XPath;

abstract class PHP extends Renderer
{
	protected $attributes;
	protected $dynamic;
	public $enableQuickRenderer = false;
	protected $out;
	protected $quickRegexp = '((?!))';
	protected $quickRenderingTest = '((?<=<)[!?])';
	protected $static;
	protected $xpath;
	abstract protected function renderNode(DOMNode $node);

	public function __sleep()
	{
		return ['enableQuickRenderer', 'params'];
	}
	protected function at(DOMNode $root, $query = null)
	{
		if ($root->nodeType === XML_TEXT_NODE)
		{
			$this->out .= htmlspecialchars($root->textContent, ENT_NOQUOTES);
		}
		else
		{
			$nodes = (isset($query)) ? $this->xpath->query($query, $root) : $root->childNodes;
			foreach ($nodes as $node)
			{
				$this->renderNode($node);
			}
		}
	}
	protected function canQuickRender($xml)
	{
		return ($this->enableQuickRenderer && !preg_match($this->quickRenderingTest, $xml) && substr($xml, -4) === '</r>');
	}
	protected function checkTagPairContent($id, $xml)
	{
		if (strpos($xml, '<' . $id, 1) !== false)
		{
			throw new RuntimeException;
		}
	}
	protected function getParamAsXPath($paramName)
	{
		return (isset($this->params[$paramName])) ? XPath::export($this->params[$paramName]) : "''";
	}
	protected function getQuickTextContent($xml)
	{
		return htmlspecialchars_decode(strip_tags($xml));
	}
	protected function hasNonNullValues(array $array)
	{
		foreach ($array as $v)
		{
			if (isset($v))
			{
				return true;
			}
		}

		return false;
	}
	protected function matchAttributes($xml)
	{
		if (strpos($xml, '="') === false)
		{
			return [];
		}
		preg_match_all('(([^ =]++)="([^"]*))S', substr($xml, 0, strpos($xml, '>')), $m);

		return array_combine($m[1], $m[2]);
	}
	protected function renderQuick($xml)
	{
		$this->attributes = [];
		$xml = $this->decodeSMP($xml);
		$html = preg_replace_callback(
			$this->quickRegexp,
			[$this, 'renderQuickCallback'],
			substr($xml, 1 + strpos($xml, '>'), -4)
		);

		return str_replace('<br/>', '<br>', $html);
	}
	protected function renderQuickCallback(array $m)
	{
		if (isset($m[3]))
		{
			return $this->renderQuickSelfClosingTag($m);
		}

		if (isset($m[2]))
		{
			$id = $m[2];
		}
		else
		{
			$id = $m[1];
			$this->checkTagPairContent($id, $m[0]);
		}

		if (isset($this->static[$id]))
		{
			return $this->static[$id];
		}
		if (isset($this->dynamic[$id]))
		{
			return preg_replace($this->dynamic[$id][0], $this->dynamic[$id][1], $m[0], 1);
		}

		return $this->renderQuickTemplate($id, $m[0]);
	}
	protected function renderQuickSelfClosingTag(array $m)
	{
		unset($m[3]);

		$m[0] = substr($m[0], 0, -2) . '>';
		$html = $this->renderQuickCallback($m);

		$m[0] = '</' . $m[2] . '>';
		$m[2] = '/' . $m[2];
		$html .= $this->renderQuickCallback($m);

		return $html;
	}
	protected function renderQuickTemplate($id, $xml)
	{
		throw new RuntimeException('Not implemented');
	}
	protected function renderRichText($xml)
	{
		$this->setLocale();

		try
		{
			if ($this->canQuickRender($xml))
			{
				$html = $this->renderQuick($xml);
				$this->restoreLocale();

				return $html;
			}
		}
		catch (RuntimeException $e)
		{
		}

		$dom         = $this->loadXML($xml);
		$this->out   = '';
		$this->xpath = new DOMXPath($dom);
		$this->at($dom->documentElement);
		$html        = $this->out;
		$this->reset();
		$this->restoreLocale();

		return $html;
	}
	protected function reset()
	{
		unset($this->attributes);
		unset($this->out);
		unset($this->xpath);
	}
}
namespace s9e\TextFormatter\Bundles\Fatdown;

class Renderer extends \s9e\TextFormatter\Renderers\PHP
{
	protected $params=['MEDIAEMBED_THEME'=>'','TASKLISTS_EDITABLE'=>''];
	protected function renderNode(\DOMNode $node)
	{
		switch($node->nodeName){case'BANDCAMP':$this->out.='<span data-s9e-mediaembed="bandcamp" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/';if($node->hasAttribute('album_id')){$this->out.='album='.htmlspecialchars($node->getAttribute('album_id'),2);if($node->hasAttribute('track_num'))$this->out.='/t='.htmlspecialchars($node->getAttribute('track_num'),2);}else$this->out.='track='.htmlspecialchars($node->getAttribute('track_id'),2);if($this->params['MEDIAEMBED_THEME']==='dark')$this->out.='/bgcol=333333/linkcol=0f91ff';$this->out.='"></iframe></span></span>';break;case'C':case'html:code':$this->out.='<code>';$this->at($node);$this->out.='</code>';break;case'CODE':$this->out.='<pre><code';if($node->hasAttribute('lang'))$this->out.=' class="language-'.htmlspecialchars($node->getAttribute('lang'),2).'"';$this->out.='>';$this->at($node);$this->out.='</code></pre>';break;case'DAILYMOTION':$this->out.='<span data-s9e-mediaembed="dailymotion" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//www.dailymotion.com/embed/video/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('t'))$this->out.='?start='.htmlspecialchars($node->getAttribute('t'),2);$this->out.='"></iframe></span></span>';break;case'DEL':case'html:del':$this->out.='<del>';$this->at($node);$this->out.='</del>';break;case'EM':$this->out.='<em>';$this->at($node);$this->out.='</em>';break;case'EMAIL':$this->out.='<a href="mailto:'.htmlspecialchars($node->getAttribute('email'),2).'">';$this->at($node);$this->out.='</a>';break;case'ESC':$this->at($node);break;case'FACEBOOK':$this->out.='<iframe data-s9e-mediaembed="facebook" allowfullscreen="" loading="lazy" onload="var c=new MessageChannel;c.port1.onmessage=function(e){style.height=e.data+\'px\'};contentWindow.postMessage(\'s9e:init\',\'https://s9e.github.io\',[c.port2])" scrolling="no" src="https://s9e.github.io/iframe/2/facebook.min.html#'.htmlspecialchars($node->getAttribute('type').$node->getAttribute('id'),2).'" style="border:0;height:360px;max-width:640px;width:100%"></iframe>';break;case'FP':case'HE':$this->out.=htmlspecialchars($node->getAttribute('char'),0);break;case'H1':$this->out.='<h1>';$this->at($node);$this->out.='</h1>';break;case'H2':$this->out.='<h2>';$this->at($node);$this->out.='</h2>';break;case'H3':$this->out.='<h3>';$this->at($node);$this->out.='</h3>';break;case'H4':$this->out.='<h4>';$this->at($node);$this->out.='</h4>';break;case'H5':$this->out.='<h5>';$this->at($node);$this->out.='</h5>';break;case'H6':$this->out.='<h6>';$this->at($node);$this->out.='</h6>';break;case'HC':$this->out.='<!--'.htmlspecialchars($node->getAttribute('content'),0).'-->';break;case'HR':$this->out.='<hr>';break;case'IMG':$this->out.='<img src="'.htmlspecialchars($node->getAttribute('src'),2).'"';if($node->hasAttribute('alt'))$this->out.=' alt="'.htmlspecialchars($node->getAttribute('alt'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';break;case'ISPOILER':$this->out.='<span class="spoiler" onclick="removeAttribute(\'style\')" style="background:#444;color:transparent">';$this->at($node);$this->out.='</span>';break;case'LI':$this->out.='<li';if($this->xpath->evaluate('boolean(TASK)',$node))$this->out.=' data-task-id="'.htmlspecialchars($this->xpath->evaluate('string(TASK/@id)',$node),2).'" data-task-state="'.htmlspecialchars($this->xpath->evaluate('string(TASK/@state)',$node),2).'"';$this->out.='>';$this->at($node);$this->out.='</li>';break;case'LIST':if(!$node->hasAttribute('type')){$this->out.='<ul>';$this->at($node);$this->out.='</ul>';}else{$this->out.='<ol';if($node->hasAttribute('start'))$this->out.=' start="'.htmlspecialchars($node->getAttribute('start'),2).'"';$this->out.='>';$this->at($node);$this->out.='</ol>';}break;case'LIVELEAK':$this->out.='<span data-s9e-mediaembed="liveleak" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" src="//www.liveleak.com/e/'.htmlspecialchars($node->getAttribute('id'),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'QUOTE':$this->out.='<blockquote>';$this->at($node);$this->out.='</blockquote>';break;case'SOUNDCLOUD':$this->out.='<iframe data-s9e-mediaembed="soundcloud" allowfullscreen="" loading="lazy" scrolling="no" src="https://w.soundcloud.com/player/?url=';if($node->hasAttribute('playlist_id'))$this->out.='https%3A//api.soundcloud.com/playlists/'.htmlspecialchars($node->getAttribute('playlist_id'),2);elseif($node->hasAttribute('track_id'))$this->out.='https%3A//api.soundcloud.com/tracks/'.htmlspecialchars($node->getAttribute('track_id'),2).'&amp;secret_token='.htmlspecialchars($node->getAttribute('secret_token'),2);else{if((strpos($node->getAttribute('id'),'://')===false))$this->out.='https%3A//soundcloud.com/';$this->out.=htmlspecialchars($node->getAttribute('id'),2);}$this->out.='" style="border:0;height:';if($node->hasAttribute('playlist_id')||(strpos($node->getAttribute('id'),'/sets/')!==false))$this->out.='450';else$this->out.='166';$this->out.='px;max-width:900px;width:100%"></iframe>';break;case'SPOILER':$this->out.='<details class="spoiler">';$this->at($node);$this->out.='</details>';break;case'SPOTIFY':if((strpos($node->getAttribute('id'),'episode/')===0)||(strpos($node->getAttribute('id'),'show/')===0))$this->out.='<iframe data-s9e-mediaembed="spotify" allow="encrypted-media" allowfullscreen="" loading="lazy" scrolling="no" src="https://open.spotify.com/embed/'.htmlspecialchars($node->getAttribute('id'),2).'" style="border:0;height:152px;max-width:900px;width:100%"></iframe>';else$this->out.='<span data-s9e-mediaembed="spotify" style="display:inline-block;width:100%;max-width:320px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:125%;padding-bottom:calc(100% + 80px)"><iframe allow="encrypted-media" allowfullscreen="" loading="lazy" scrolling="no" src="https://open.spotify.com/embed/'.htmlspecialchars(strtr($node->getAttribute('id'),':','/').$node->getAttribute('path'),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'STRONG':case'html:strong':$this->out.='<strong>';$this->at($node);$this->out.='</strong>';break;case'SUB':case'html:sub':$this->out.='<sub>';$this->at($node);$this->out.='</sub>';break;case'SUP':case'html:sup':$this->out.='<sup>';$this->at($node);$this->out.='</sup>';break;case'TABLE':case'html:table':$this->out.='<table>';$this->at($node);$this->out.='</table>';break;case'TASK':$this->out.='<input data-task-id="'.htmlspecialchars($node->getAttribute('id'),2).'" type="checkbox"';if($node->getAttribute('state')==='checked')$this->out.=' checked';if($this->params['TASKLISTS_EDITABLE']==='')$this->out.=' disabled';$this->out.='>';break;case'TBODY':case'html:tbody':$this->out.='<tbody>';$this->at($node);$this->out.='</tbody>';break;case'TD':$this->out.='<td';if($node->hasAttribute('align'))$this->out.=' style="text-align:'.htmlspecialchars($node->getAttribute('align'),2).'"';$this->out.='>';$this->at($node);$this->out.='</td>';break;case'TH':$this->out.='<th';if($node->hasAttribute('align'))$this->out.=' style="text-align:'.htmlspecialchars($node->getAttribute('align'),2).'"';$this->out.='>';$this->at($node);$this->out.='</th>';break;case'THEAD':case'html:thead':$this->out.='<thead>';$this->at($node);$this->out.='</thead>';break;case'TR':case'html:tr':$this->out.='<tr>';$this->at($node);$this->out.='</tr>';break;case'TWITCH':$this->out.='<span data-s9e-mediaembed="twitch" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" onload="contentWindow.postMessage(\'\',\'https://s9e.github.io\')" scrolling="no" src="https://s9e.github.io/iframe/2/twitch.min.html#channel='.htmlspecialchars($node->getAttribute('channel'),2).';clip_id='.htmlspecialchars($node->getAttribute('clip_id'),2).';t='.htmlspecialchars($node->getAttribute('t'),2).';video_id='.htmlspecialchars($node->getAttribute('video_id'),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'URL':$this->out.='<a href="'.htmlspecialchars($node->getAttribute('url'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';$this->at($node);$this->out.='</a>';break;case'VIMEO':$this->out.='<span data-s9e-mediaembed="vimeo" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//player.vimeo.com/video/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('t'))$this->out.='#t='.htmlspecialchars($node->getAttribute('t'),2);$this->out.='"></iframe></span></span>';break;case'VINE':$this->out.='<span data-s9e-mediaembed="vine" style="display:inline-block;width:100%;max-width:480px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" loading="lazy" scrolling="no" src="https://vine.co/v/'.htmlspecialchars($node->getAttribute('id'),2).'/embed/simple?audio=1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'YOUTUBE':$this->out.='<span data-s9e-mediaembed="youtube" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="background:url(https://i.ytimg.com/vi/'.htmlspecialchars($node->getAttribute('id'),2).'/hqdefault.jpg) 50% 50% / cover;border:0;height:100%;left:0;position:absolute;width:100%" src="https://www.youtube.com/embed/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('list'))$this->out.='?list='.htmlspecialchars($node->getAttribute('list'),2);if($node->hasAttribute('t')){if($node->hasAttribute('list'))$this->out.='&amp;';else$this->out.='?';$this->out.='start='.htmlspecialchars($node->getAttribute('t'),2);}$this->out.='"></iframe></span></span>';break;case'br':case'html:br':$this->out.='<br>';break;case'e':case'i':case's':break;case'html:abbr':$this->out.='<abbr';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';$this->at($node);$this->out.='</abbr>';break;case'html:b':$this->out.='<b>';$this->at($node);$this->out.='</b>';break;case'html:dd':$this->out.='<dd>';$this->at($node);$this->out.='</dd>';break;case'html:div':$this->out.='<div';if($node->hasAttribute('class'))$this->out.=' class="'.htmlspecialchars($node->getAttribute('class'),2).'"';$this->out.='>';$this->at($node);$this->out.='</div>';break;case'html:dl':$this->out.='<dl>';$this->at($node);$this->out.='</dl>';break;case'html:dt':$this->out.='<dt>';$this->at($node);$this->out.='</dt>';break;case'html:i':$this->out.='<i>';$this->at($node);$this->out.='</i>';break;case'html:img':$this->out.='<img';if($node->hasAttribute('alt'))$this->out.=' alt="'.htmlspecialchars($node->getAttribute('alt'),2).'"';if($node->hasAttribute('height'))$this->out.=' height="'.htmlspecialchars($node->getAttribute('height'),2).'"';if($node->hasAttribute('src'))$this->out.=' src="'.htmlspecialchars($node->getAttribute('src'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';if($node->hasAttribute('width'))$this->out.=' width="'.htmlspecialchars($node->getAttribute('width'),2).'"';$this->out.='>';break;case'html:ins':$this->out.='<ins>';$this->at($node);$this->out.='</ins>';break;case'html:li':$this->out.='<li>';$this->at($node);$this->out.='</li>';break;case'html:ol':$this->out.='<ol>';$this->at($node);$this->out.='</ol>';break;case'html:pre':$this->out.='<pre>';$this->at($node);$this->out.='</pre>';break;case'html:rb':$this->out.='<rb>';$this->at($node);$this->out.='</rb>';break;case'html:rp':$this->out.='<rp>';$this->at($node);$this->out.='</rp>';break;case'html:rt':$this->out.='<rt>';$this->at($node);$this->out.='</rt>';break;case'html:rtc':$this->out.='<rtc>';$this->at($node);$this->out.='</rtc>';break;case'html:ruby':$this->out.='<ruby>';$this->at($node);$this->out.='</ruby>';break;case'html:span':$this->out.='<span';if($node->hasAttribute('class'))$this->out.=' class="'.htmlspecialchars($node->getAttribute('class'),2).'"';$this->out.='>';$this->at($node);$this->out.='</span>';break;case'html:td':$this->out.='<td';if($node->hasAttribute('colspan'))$this->out.=' colspan="'.htmlspecialchars($node->getAttribute('colspan'),2).'"';if($node->hasAttribute('rowspan'))$this->out.=' rowspan="'.htmlspecialchars($node->getAttribute('rowspan'),2).'"';$this->out.='>';$this->at($node);$this->out.='</td>';break;case'html:tfoot':$this->out.='<tfoot>';$this->at($node);$this->out.='</tfoot>';break;case'html:th':$this->out.='<th';if($node->hasAttribute('colspan'))$this->out.=' colspan="'.htmlspecialchars($node->getAttribute('colspan'),2).'"';if($node->hasAttribute('rowspan'))$this->out.=' rowspan="'.htmlspecialchars($node->getAttribute('rowspan'),2).'"';if($node->hasAttribute('scope'))$this->out.=' scope="'.htmlspecialchars($node->getAttribute('scope'),2).'"';$this->out.='>';$this->at($node);$this->out.='</th>';break;case'html:u':$this->out.='<u>';$this->at($node);$this->out.='</u>';break;case'html:ul':$this->out.='<ul>';$this->at($node);$this->out.='</ul>';break;case'p':$this->out.='<p>';$this->at($node);$this->out.='</p>';break;default:$this->at($node);}
	}
	public $enableQuickRenderer=true;
	protected $static=['/C'=>'</code>','/CODE'=>'</code></pre>','/DEL'=>'</del>','/EM'=>'</em>','/EMAIL'=>'</a>','/ESC'=>'','/H1'=>'</h1>','/H2'=>'</h2>','/H3'=>'</h3>','/H4'=>'</h4>','/H5'=>'</h5>','/H6'=>'</h6>','/ISPOILER'=>'</span>','/QUOTE'=>'</blockquote>','/SPOILER'=>'</details>','/STRONG'=>'</strong>','/SUB'=>'</sub>','/SUP'=>'</sup>','/TABLE'=>'</table>','/TBODY'=>'</tbody>','/TD'=>'</td>','/TH'=>'</th>','/THEAD'=>'</thead>','/TR'=>'</tr>','/URL'=>'</a>','/html:abbr'=>'</abbr>','/html:b'=>'</b>','/html:code'=>'</code>','/html:dd'=>'</dd>','/html:del'=>'</del>','/html:div'=>'</div>','/html:dl'=>'</dl>','/html:dt'=>'</dt>','/html:i'=>'</i>','/html:ins'=>'</ins>','/html:li'=>'</li>','/html:ol'=>'</ol>','/html:pre'=>'</pre>','/html:rb'=>'</rb>','/html:rp'=>'</rp>','/html:rt'=>'</rt>','/html:rtc'=>'</rtc>','/html:ruby'=>'</ruby>','/html:span'=>'</span>','/html:strong'=>'</strong>','/html:sub'=>'</sub>','/html:sup'=>'</sup>','/html:table'=>'</table>','/html:tbody'=>'</tbody>','/html:td'=>'</td>','/html:tfoot'=>'</tfoot>','/html:th'=>'</th>','/html:thead'=>'</thead>','/html:tr'=>'</tr>','/html:u'=>'</u>','/html:ul'=>'</ul>','C'=>'<code>','DEL'=>'<del>','EM'=>'<em>','ESC'=>'','H1'=>'<h1>','H2'=>'<h2>','H3'=>'<h3>','H4'=>'<h4>','H5'=>'<h5>','H6'=>'<h6>','HR'=>'<hr>','ISPOILER'=>'<span class="spoiler" onclick="removeAttribute(\'style\')" style="background:#444;color:transparent">','QUOTE'=>'<blockquote>','SPOILER'=>'<details class="spoiler">','STRONG'=>'<strong>','SUB'=>'<sub>','SUP'=>'<sup>','TABLE'=>'<table>','TBODY'=>'<tbody>','THEAD'=>'<thead>','TR'=>'<tr>','html:b'=>'<b>','html:br'=>'<br>','html:code'=>'<code>','html:dd'=>'<dd>','html:del'=>'<del>','html:dl'=>'<dl>','html:dt'=>'<dt>','html:i'=>'<i>','html:ins'=>'<ins>','html:li'=>'<li>','html:ol'=>'<ol>','html:pre'=>'<pre>','html:rb'=>'<rb>','html:rp'=>'<rp>','html:rt'=>'<rt>','html:rtc'=>'<rtc>','html:ruby'=>'<ruby>','html:strong'=>'<strong>','html:sub'=>'<sub>','html:sup'=>'<sup>','html:table'=>'<table>','html:tbody'=>'<tbody>','html:tfoot'=>'<tfoot>','html:thead'=>'<thead>','html:tr'=>'<tr>','html:u'=>'<u>','html:ul'=>'<ul>'];
	protected $dynamic=['EMAIL'=>['(^[^ ]+(?> (?!email=)[^=]+="[^"]*")*(?> email="([^"]*)")?.*)s','<a href="mailto:$1">'],'IMG'=>['(^[^ ]+(?> (?!(?:alt|src|title)=)[^=]+="[^"]*")*( alt="[^"]*")?(?> (?!(?:src|title)=)[^=]+="[^"]*")*(?> src="([^"]*)")?(?> (?!title=)[^=]+="[^"]*")*( title="[^"]*")?.*)s','<img src="$2"$1$3>'],'LIVELEAK'=>['(^[^ ]+(?> (?!id=)[^=]+="[^"]*")*(?> id="([^"]*)")?.*)s','<span data-s9e-mediaembed="liveleak" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" src="//www.liveleak.com/e/$1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>'],'TWITCH'=>['(^[^ ]+(?> (?!(?:c(?:hannel|lip_id)|t|video_id)=)[^=]+="[^"]*")*(?> channel="([^"]*)")?(?> (?!(?:clip_id|t|video_id)=)[^=]+="[^"]*")*(?> clip_id="([^"]*)")?(?> (?!(?:t|video_id)=)[^=]+="[^"]*")*(?> t="([^"]*)")?(?> (?!video_id=)[^=]+="[^"]*")*(?> video_id="([^"]*)")?.*)s','<span data-s9e-mediaembed="twitch" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" onload="contentWindow.postMessage(\'\',\'https://s9e.github.io\')" scrolling="no" src="https://s9e.github.io/iframe/2/twitch.min.html#channel=$1;clip_id=$2;t=$3;video_id=$4" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>'],'URL'=>['(^[^ ]+(?> (?!(?:title|url)=)[^=]+="[^"]*")*( title="[^"]*")?(?> (?!url=)[^=]+="[^"]*")*(?> url="([^"]*)")?.*)s','<a href="$2"$1>'],'VINE'=>['(^[^ ]+(?> (?!id=)[^=]+="[^"]*")*(?> id="([^"]*)")?.*)s','<span data-s9e-mediaembed="vine" style="display:inline-block;width:100%;max-width:480px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" loading="lazy" scrolling="no" src="https://vine.co/v/$1/embed/simple?audio=1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>'],'html:abbr'=>['(^[^ ]+(?> (?!title=)[^=]+="[^"]*")*( title="[^"]*")?.*)s','<abbr$1>'],'html:div'=>['(^[^ ]+(?> (?!class=)[^=]+="[^"]*")*( class="[^"]*")?.*)s','<div$1>'],'html:img'=>['(^[^ ]+(?> (?!(?:alt|height|src|title|width)=)[^=]+="[^"]*")*( alt="[^"]*")?(?> (?!(?:height|src|title|width)=)[^=]+="[^"]*")*( height="[^"]*")?(?> (?!(?:src|title|width)=)[^=]+="[^"]*")*( src="[^"]*")?(?> (?!(?:title|width)=)[^=]+="[^"]*")*( title="[^"]*")?(?> (?!width=)[^=]+="[^"]*")*( width="[^"]*")?.*)s','<img$1$2$3$4$5>'],'html:span'=>['(^[^ ]+(?> (?!class=)[^=]+="[^"]*")*( class="[^"]*")?.*)s','<span$1>'],'html:td'=>['(^[^ ]+(?> (?!(?:col|row)span=)[^=]+="[^"]*")*( colspan="[^"]*")?(?> (?!rowspan=)[^=]+="[^"]*")*( rowspan="[^"]*")?.*)s','<td$1$2>'],'html:th'=>['(^[^ ]+(?> (?!(?:colspan|rowspan|scope)=)[^=]+="[^"]*")*( colspan="[^"]*")?(?> (?!(?:rowspan|scope)=)[^=]+="[^"]*")*( rowspan="[^"]*")?(?> (?!scope=)[^=]+="[^"]*")*( scope="[^"]*")?.*)s','<th$1$2$3>']];
	protected $quickRegexp='(<(?:(?!/)((?:BANDCAMP|DAILYMOTION|F(?:ACEBOOK|P)|H[CER]|IMG|LIVELEAK|S(?:OUNDCLOUD|POTIFY)|T(?:ASK|WITCH)|VI(?:MEO|NE)|YOUTUBE|html:(?:br|img)))(?: [^>]*)?>.*?</\\1|(/?(?!br/|p>)[^ />]+)[^>]*?(/)?)>)s';
	protected $quickRenderingTest='((?<=<)(?:[!?]|LI[ />]))';
	protected function renderQuickTemplate($id, $xml)
	{
		$attributes=$this->matchAttributes($xml);
		$html='';switch($id){case'/LIST':$attributes=array_pop($this->attributes);if(!isset($attributes['type']))$html.='</ul>';else$html.='</ol>';break;case'BANDCAMP':$attributes+=['track_num'=>null,'track_id'=>null];$html.='<span data-s9e-mediaembed="bandcamp" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/';if(isset($attributes['album_id'])){$html.='album='.$attributes['album_id'];if(isset($attributes['track_num']))$html.='/t='.$attributes['track_num'];}else$html.='track='.$attributes['track_id'];if($this->params['MEDIAEMBED_THEME']==='dark')$html.='/bgcol=333333/linkcol=0f91ff';$html.='"></iframe></span></span>';break;case'CODE':$html.='<pre><code';if(isset($attributes['lang']))$html.=' class="language-'.$attributes['lang'].'"';$html.='>';break;case'DAILYMOTION':$attributes+=['id'=>null];$html.='<span data-s9e-mediaembed="dailymotion" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//www.dailymotion.com/embed/video/'.$attributes['id'];if(isset($attributes['t']))$html.='?start='.$attributes['t'];$html.='"></iframe></span></span>';break;case'FACEBOOK':$attributes+=['type'=>null,'id'=>null];$html.='<iframe data-s9e-mediaembed="facebook" allowfullscreen="" loading="lazy" onload="var c=new MessageChannel;c.port1.onmessage=function(e){style.height=e.data+\'px\'};contentWindow.postMessage(\'s9e:init\',\'https://s9e.github.io\',[c.port2])" scrolling="no" src="https://s9e.github.io/iframe/2/facebook.min.html#'.$attributes['type'].$attributes['id'].'" style="border:0;height:360px;max-width:640px;width:100%"></iframe>';break;case'FP':case'HE':$attributes+=['char'=>null];$html.=str_replace('&quot;','"',$attributes['char']);break;case'HC':$attributes+=['content'=>null];$html.='<!--'.str_replace('&quot;','"',$attributes['content']).'-->';break;case'LIST':if(!isset($attributes['type']))$html.='<ul>';else{$html.='<ol';if(isset($attributes['start']))$html.=' start="'.$attributes['start'].'"';$html.='>';}$this->attributes[]=$attributes;break;case'SOUNDCLOUD':$attributes+=['secret_token'=>null,'id'=>null];$html.='<iframe data-s9e-mediaembed="soundcloud" allowfullscreen="" loading="lazy" scrolling="no" src="https://w.soundcloud.com/player/?url=';if(isset($attributes['playlist_id']))$html.='https%3A//api.soundcloud.com/playlists/'.$attributes['playlist_id'];elseif(isset($attributes['track_id']))$html.='https%3A//api.soundcloud.com/tracks/'.$attributes['track_id'].'&amp;secret_token='.$attributes['secret_token'];else{if((strpos($attributes['id'],'://')===false))$html.='https%3A//soundcloud.com/';$html.=$attributes['id'];}$html.='" style="border:0;height:';if(isset($attributes['playlist_id'])||(strpos($attributes['id'],'/sets/')!==false))$html.='450';else$html.='166';$html.='px;max-width:900px;width:100%"></iframe>';break;case'SPOTIFY':$attributes+=['id'=>null,'path'=>null];if((strpos($attributes['id'],'episode/')===0)||(strpos($attributes['id'],'show/')===0))$html.='<iframe data-s9e-mediaembed="spotify" allow="encrypted-media" allowfullscreen="" loading="lazy" scrolling="no" src="https://open.spotify.com/embed/'.$attributes['id'].'" style="border:0;height:152px;max-width:900px;width:100%"></iframe>';else$html.='<span data-s9e-mediaembed="spotify" style="display:inline-block;width:100%;max-width:320px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:125%;padding-bottom:calc(100% + 80px)"><iframe allow="encrypted-media" allowfullscreen="" loading="lazy" scrolling="no" src="https://open.spotify.com/embed/'.htmlspecialchars(strtr(htmlspecialchars_decode($attributes['id']),':','/').htmlspecialchars_decode($attributes['path']),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'TASK':$attributes+=['id'=>null,'state'=>null];$html.='<input data-task-id="'.$attributes['id'].'" type="checkbox"';if($attributes['state']==='checked')$html.=' checked';if($this->params['TASKLISTS_EDITABLE']==='')$html.=' disabled';$html.='>';break;case'TD':$html.='<td';if(isset($attributes['align']))$html.=' style="text-align:'.$attributes['align'].'"';$html.='>';break;case'TH':$html.='<th';if(isset($attributes['align']))$html.=' style="text-align:'.$attributes['align'].'"';$html.='>';break;case'VIMEO':$attributes+=['id'=>null];$html.='<span data-s9e-mediaembed="vimeo" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//player.vimeo.com/video/'.$attributes['id'];if(isset($attributes['t']))$html.='#t='.$attributes['t'];$html.='"></iframe></span></span>';break;case'YOUTUBE':$attributes+=['id'=>null,'t'=>null];$html.='<span data-s9e-mediaembed="youtube" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" loading="lazy" scrolling="no" style="background:url(https://i.ytimg.com/vi/'.$attributes['id'].'/hqdefault.jpg) 50% 50% / cover;border:0;height:100%;left:0;position:absolute;width:100%" src="https://www.youtube.com/embed/'.$attributes['id'];if(isset($attributes['list']))$html.='?list='.$attributes['list'];if(isset($attributes['t'])){if(isset($attributes['list']))$html.='&amp;';else$html.='?';$html.='start='.$attributes['t'];}$html.='"></iframe></span></span>';}

		return $html;
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class InlineSpoiler extends AbstractInlineMarkup
{
	public function parse()
	{
		$this->parseInlineMarkup('>!', '/>![^\\x17]+?!</',         'ISPOILER');
		$this->parseInlineMarkup('||', '/\\|\\|[^\\x17]+?\\|\\|/', 'ISPOILER');
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class Strikethrough extends AbstractInlineMarkup
{
	public function parse()
	{
		$this->parseInlineMarkup('~~', '/~~[^\\x17]+?~~(?!~)/', 'DEL');
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class Subscript extends AbstractScript
{
	public function parse()
	{
		$this->parseAbstractScript('SUB', '~', '/~[^\\x17\\s!"#$%&\'()*+,\\-.\\/:;<=>?@[\\]^_`{}|~]++~?/', '/~\\([^\\x17()]++\\)/');
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class Superscript extends AbstractScript
{
	public function parse()
	{
		$this->parseAbstractScript('SUP', '^', '/\\^[^\\x17\\s!"#$%&\'()*+,\\-.\\/:;<=>?@[\\]^_`{}|~]++\\^?/', '/\\^\\([^\\x17()]++\\)/');
	}
}
namespace s9e\TextFormatter;
const VERSION = '2.8.0';
