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
		if (isset($pluginConfig['regexp']))
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
		 && !$tag->getEndTag()
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
		if ($tagFlags & self::RULE_IS_TRANSPARENT)
		{
			foreach ($this->context['allowed'] as $k => $v)
			{
				$allowed[] = $tagConfig['allowed'][$k] & $v;
			}
		}
		else
		{
			foreach ($this->context['allowed'] as $k => $v)
			{
				$allowed[] = $tagConfig['allowed'][$k] & (($v & 0xFF00) | ($v >> 8));
			}
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
namespace s9e\TextFormatter\Parser;

use s9e\TextFormatter\Parser;
use s9e\TextFormatter\Parser\Logger;
use s9e\TextFormatter\Parser\Tag;

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
		if ($this->name === $tag->name)
		{
			if ($this->type === self::START_TAG
			 && $tag->type  === self::END_TAG
			 && $tag->pos   >=  $this->pos)
			{
				$this->endTag  = $tag;
				$tag->startTag = $this;

				$this->cascadeInvalidationTo($tag);
			}
			elseif ($this->type === self::END_TAG
			     && $tag->type  === self::START_TAG
			     && $tag->pos   <=  $this->pos)
			{
				$this->startTag = $tag;
				$tag->endTag    = $this;
			}
		}
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
		 || $this->name !== $startTag->name
		 || $startTag->type !== self::START_TAG
		 || $this->type !== self::END_TAG
		 || $this->pos < $startTag->pos
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
		if (strpos($text, '\\') !== false && preg_match('/\\\\[!"\'()*[\\\\\\]^_`~]/', $text))
		{
			$this->hasEscapedChars = true;
			$text = strtr(
				$text,
				[
					'\\!' => "\x1B0", '\\"' => "\x1B1", "\\'" => "\x1B2", '\\('  => "\x1B3",
					'\\)' => "\x1B4", '\\*' => "\x1B5", '\\[' => "\x1B6", '\\\\' => "\x1B7",
					'\\]' => "\x1B8", '\\^' => "\x1B9", '\\_' => "\x1BA", '\\`'  => "\x1BB",
					'\\~' => "\x1BC"
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
					"\x1B0" => '!', "\x1B1" => '"', "\x1B2" => "'", "\x1B3" => '(',
					"\x1B4" => ')', "\x1B5" => '*', "\x1B6" => '[', "\x1B7" => '\\',
					"\x1B8" => ']', "\x1B9" => '^', "\x1BA" => '_', "\x1BB" => '`',
					"\x1BC" => '~'
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
namespace s9e\TextFormatter;

use DOMDocument;
use InvalidArgumentException;

abstract class Renderer
{
	protected $params = [];
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
		if (strpos($xml, '<!') !== false)
		{
			throw new InvalidArgumentException('DTDs, CDATA nodes and comments are not allowed');
		}
		if (strpos($xml, '<?') !== false)
		{
			throw new InvalidArgumentException('Processing instructions are not allowed');
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
}
namespace s9e\TextFormatter;

use DOMDocument;
use DOMXPath;

abstract class Utils
{
	public static function getAttributeValues($xml, $tagName, $attrName)
	{
		$values = [];
		if (strpos($xml, '<' . $tagName) !== false)
		{
			$regexp = '(<' . preg_quote($tagName) . '(?= )[^>]*? ' . preg_quote($attrName) . '="([^"]*+))';
			preg_match_all($regexp, $xml, $matches);
			foreach ($matches[1] as $value)
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
		if (strpos($xml, '<' . $tagName) === false)
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
		if (strpos($xml, '<' . $tagName) === false)
		{
			return $xml;
		}

		return preg_replace_callback(
			'((<' . preg_quote($tagName) . ')(?=[ />])[^>]*?(/?>))',
			function ($m) use ($callback)
			{
				return $m[1] . self::serializeAttributes($callback(self::parseAttributes($m[0]))) . $m[2];
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
			preg_match_all('(([^ =]++)="([^"]*))S', $xml, $matches);
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
	public static function getParser()
	{
		return unserialize('O:24:"s9e\\TextFormatter\\Parser":4:{s:16:"' . "\0" . '*' . "\0" . 'pluginsConfig";a:10:{s:9:"Autoemail";a:5:{s:8:"attrName";s:5:"email";s:10:"quickMatch";s:1:"@";s:6:"regexp";s:39:"/\\b[-a-z0-9_+.]+@[-a-z0-9.]*[a-z0-9]/Si";s:7:"tagName";s:5:"EMAIL";s:11:"regexpLimit";i:50000;}s:8:"Autolink";a:5:{s:8:"attrName";s:3:"url";s:6:"regexp";s:139:"#\\b(?:ftp|https?|mailto)://\\S(?>[^\\s()\\[\\]\\x{FF01}-\\x{FF0F}\\x{FF1A}-\\x{FF20}\\x{FF3B}-\\x{FF40}\\x{FF5B}-\\x{FF65}]|\\([^\\s()]*\\)|\\[\\w*\\])++#Siu";s:7:"tagName";s:3:"URL";s:10:"quickMatch";s:3:"://";s:11:"regexpLimit";i:50000;}s:7:"Escaper";a:4:{s:10:"quickMatch";s:1:"\\";s:6:"regexp";s:29:"/\\\\[-!#()*+.:<>@[\\\\\\]^_`{|}]/";s:7:"tagName";s:3:"ESC";s:11:"regexpLimit";i:50000;}s:10:"FancyPants";a:2:{s:8:"attrName";s:4:"char";s:7:"tagName";s:2:"FP";}s:12:"HTMLComments";a:5:{s:8:"attrName";s:7:"content";s:10:"quickMatch";s:4:"<!--";s:6:"regexp";s:22:"/<!--(?!\\[if).*?-->/is";s:7:"tagName";s:2:"HC";s:11:"regexpLimit";i:50000;}s:12:"HTMLElements";a:5:{s:10:"quickMatch";s:1:"<";s:6:"prefix";s:4:"html";s:6:"regexp";s:385:"#<(?>/((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))|((?:a(?:bbr)?|br?|code|d(?:[dlt]|el|iv)|em|hr|i(?:mg|ns)?|li|ol|pre|r(?:[bp]|tc?|uby)|s(?:pan|trong|u[bp])?|t(?:[dr]|able|body|foot|h(?:ead)?)|ul?))((?>\\s+[a-z][-a-z0-9]*(?>\\s*=\\s*(?>"[^"]*"|\'[^\']*\'|[^\\s"\'=<>`]+))?)*+)\\s*/?)\\s*>#i";s:7:"aliases";a:6:{s:1:"a";a:2:{s:0:"";s:3:"URL";s:4:"href";s:3:"url";}s:2:"hr";a:1:{s:0:"";s:2:"HR";}s:2:"em";a:1:{s:0:"";s:2:"EM";}s:1:"s";a:1:{s:0:"";s:1:"S";}s:6:"strong";a:1:{s:0:"";s:6:"STRONG";}s:3:"sup";a:1:{s:0:"";s:3:"SUP";}}s:11:"regexpLimit";i:50000;}s:12:"HTMLEntities";a:5:{s:8:"attrName";s:4:"char";s:10:"quickMatch";s:1:"&";s:6:"regexp";s:38:"/&(?>[a-z]+|#(?>[0-9]+|x[0-9a-f]+));/i";s:7:"tagName";s:2:"HE";s:11:"regexpLimit";i:50000;}s:8:"Litedown";a:1:{s:18:"decodeHtmlEntities";b:1;}s:10:"MediaEmbed";a:4:{s:10:"quickMatch";s:3:"://";s:6:"regexp";s:26:"/\\bhttps?:\\/\\/[^["\'\\s]+/Si";s:7:"tagName";s:5:"MEDIA";s:11:"regexpLimit";i:50000;}s:10:"PipeTables";a:3:{s:16:"overwriteEscapes";b:1;s:17:"overwriteMarkdown";b:1;s:10:"quickMatch";s:1:"|";}}s:14:"registeredVars";a:3:{s:9:"urlConfig";a:1:{s:14:"allowedSchemes";s:27:"/^(?:ftp|https?|mailto)$/Di";}s:16:"MediaEmbed.hosts";a:13:{s:12:"bandcamp.com";s:8:"bandcamp";s:6:"dai.ly";s:11:"dailymotion";s:15:"dailymotion.com";s:11:"dailymotion";s:12:"facebook.com";s:8:"facebook";s:12:"liveleak.com";s:8:"liveleak";s:14:"soundcloud.com";s:10:"soundcloud";s:16:"open.spotify.com";s:7:"spotify";s:16:"play.spotify.com";s:7:"spotify";s:9:"twitch.tv";s:6:"twitch";s:9:"vimeo.com";s:5:"vimeo";s:7:"vine.co";s:4:"vine";s:11:"youtube.com";s:7:"youtube";s:8:"youtu.be";s:7:"youtube";}s:16:"MediaEmbed.sites";a:10:{s:8:"bandcamp";a:2:{i:0;a:0:{}i:1;a:2:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:25:"!/album=(?\'album_id\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:8:"album_id";}}}s:5:"match";a:1:{i:0;a:2:{i:0;s:23:"!bandcamp\\.com/album/.!";i:1;a:1:{i:0;s:0:"";}}}}i:1;a:2:{s:7:"extract";a:3:{i:0;a:2:{i:0;s:29:"!"album_id":(?\'album_id\'\\d+)!";i:1;R:90;}i:1;a:2:{i:0;s:31:"!"track_num":(?\'track_num\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:9:"track_num";}}i:2;a:2:{i:0;s:25:"!/track=(?\'track_id\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:8:"track_id";}}}s:5:"match";a:1:{i:0;a:2:{i:0;s:23:"!bandcamp\\.com/track/.!";i:1;R:96;}}}}}s:11:"dailymotion";a:2:{i:0;a:3:{i:0;a:2:{i:0;s:27:"!dai\\.ly/(?\'id\'[a-z0-9]+)!i";i:1;a:2:{i:0;s:0:"";i:1;s:2:"id";}}i:1;a:2:{i:0;s:92:"!dailymotion\\.com/(?:live/|swf/|user/[^#]+#video=|(?:related/\\d+/)?video/)(?\'id\'[a-z0-9]+)!i";i:1;R:119;}i:2;a:2:{i:0;s:17:"!start=(?\'t\'\\d+)!";i:1;a:2:{i:0;s:0:"";i:1;s:1:"t";}}}i:1;R:84;}s:8:"facebook";a:2:{i:0;a:3:{i:0;a:2:{i:0;s:135:"@/(?!(?:apps|developers|graph)\\.)[-\\w.]*facebook\\.com/(?:[/\\w]+/permalink|(?!pages/|groups/).*?)(?:/|fbid=|\\?v=)(?\'id\'\\d+)(?=$|[/?&#])@";i:1;R:119;}i:1;a:2:{i:0;s:51:"@facebook\\.com/(?\'user\'\\w+)/(?\'type\'post|video)s?/@";i:1;a:3:{i:0;s:0:"";i:1;s:4:"user";i:2;s:4:"type";}}i:2;a:2:{i:0;s:46:"@facebook\\.com/video/(?\'type\'post|video)\\.php@";i:1;a:2:{i:0;s:0:"";i:1;s:4:"type";}}}i:1;R:84;}s:8:"liveleak";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:41:"!liveleak\\.com/(?:e/|view\\?i=)(?\'id\'\\w+)!";i:1;R:119;}}i:1;a:1:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:28:"!liveleak\\.com/e/(?\'id\'\\w+)!";i:1;R:119;}}s:5:"match";a:1:{i:0;a:2:{i:0;s:24:"!liveleak\\.com/view\\?t=!";i:1;R:96;}}}}}s:10:"soundcloud";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:84:"@https?://(?:api\\.)?soundcloud\\.com/(?!pages/)(?\'id\'[-/\\w]+/[-/\\w]+|^[^/]+/[^/]+$)@i";i:1;R:119;}i:1;a:2:{i:0;s:52:"@api\\.soundcloud\\.com/playlists/(?\'playlist_id\'\\d+)@";i:1;a:2:{i:0;s:0:"";i:1;s:11:"playlist_id";}}i:2;a:2:{i:0;s:89:"@api\\.soundcloud\\.com/tracks/(?\'track_id\'\\d+)(?:\\?secret_token=(?\'secret_token\'[-\\w]+))?@";i:1;a:3:{i:0;s:0:"";i:1;s:8:"track_id";i:2;s:12:"secret_token";}}i:3;a:2:{i:0;s:81:"@soundcloud\\.com/(?!playlists|tracks)[-\\w]+/[-\\w]+/(?=s-)(?\'secret_token\'[-\\w]+)@";i:1;a:2:{i:0;s:0:"";i:1;s:12:"secret_token";}}}i:1;a:2:{i:0;a:3:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:36:"@soundcloud:tracks:(?\'track_id\'\\d+)@";i:1;R:109;}}s:6:"header";s:29:"User-agent: PHP (not Mozilla)";s:5:"match";a:1:{i:0;a:2:{i:0;s:56:"@soundcloud\\.com/(?!playlists/\\d|tracks/\\d)[-\\w]+/[-\\w]@";i:1;R:96;}}}i:1;a:3:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:44:"@soundcloud://playlists:(?\'playlist_id\'\\d+)@";i:1;R:162;}}s:6:"header";s:29:"User-agent: PHP (not Mozilla)";s:5:"match";a:1:{i:0;a:2:{i:0;s:27:"@soundcloud\\.com/\\w+/sets/@";i:1;R:96;}}}}}s:7:"spotify";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:102:"!(?:open|play)\\.spotify\\.com/(?\'id\'(?:user/[-.\\w]+/)?(?:album|artist|playlist|track)(?:[:/][-.\\w]+)+)!";i:1;R:119;}}i:1;R:84;}s:6:"twitch";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:47:"#twitch\\.tv/(?:videos|\\w+/v)/(?\'video_id\'\\d+)?#";i:1;a:2:{i:0;s:0:"";i:1;s:8:"video_id";}}i:1;a:2:{i:0;s:44:"#www\\.twitch\\.tv/(?!videos/)(?\'channel\'\\w+)#";i:1;a:2:{i:0;s:0:"";i:1;s:7:"channel";}}i:2;a:2:{i:0;s:32:"#t=(?\'t\'(?:(?:\\d+h)?\\d+m)?\\d+s)#";i:1;R:126;}i:3;a:2:{i:0;s:56:"#clips\\.twitch\\.tv/(?:(?\'channel\'\\w+)/)?(?\'clip_id\'\\w+)#";i:1;a:3:{i:0;s:0:"";i:1;s:7:"channel";i:2;s:7:"clip_id";}}}i:1;R:84;}s:5:"vimeo";a:2:{i:0;a:2:{i:0;a:2:{i:0;s:50:"!vimeo\\.com/(?:channels/[^/]+/|video/)?(?\'id\'\\d+)!";i:1;R:119;}i:1;a:2:{i:0;s:19:"!#t=(?\'t\'[\\dhms]+)!";i:1;R:126;}}i:1;R:84;}s:4:"vine";a:2:{i:0;a:1:{i:0;a:2:{i:0;s:25:"!vine\\.co/v/(?\'id\'[^/]+)!";i:1;R:119;}}i:1;R:84;}s:7:"youtube";a:2:{i:0;a:4:{i:0;a:2:{i:0;s:69:"!youtube\\.com/(?:watch.*?v=|v/|attribution_link.*?v%3D)(?\'id\'[-\\w]+)!";i:1;R:119;}i:1;a:2:{i:0;s:25:"!youtu\\.be/(?\'id\'[-\\w]+)!";i:1;R:119;}i:2;a:2:{i:0;s:25:"@[#&?]t=(?\'t\'\\d[\\dhms]*)@";i:1;R:126;}i:3;a:2:{i:0;s:26:"![&?]list=(?\'list\'[-\\w]+)!";i:1;a:2:{i:0;s:0:"";i:1;s:4:"list";}}}i:1;a:1:{i:0;a:2:{s:7:"extract";a:1:{i:0;a:2:{i:0;s:19:"!/vi/(?\'id\'[-\\w]+)!";i:1;R:119;}}s:5:"match";a:1:{i:0;a:2:{i:0;s:14:"!/shared\\?ci=!";i:1;R:96;}}}}}}}s:14:"' . "\0" . '*' . "\0" . 'rootContext";a:2:{s:7:"allowed";a:3:{i:0;i:65527;i:1;i:65329;i:2;i:257;}s:5:"flags";i:8;}s:13:"' . "\0" . '*' . "\0" . 'tagsConfig";a:76:{s:8:"BANDCAMP";a:7:{s:10:"attributes";a:3:{s:8:"album_id";a:2:{s:8:"required";b:0;s:11:"filterChain";R:84;}s:8:"track_id";R:257;s:9:"track_num";R:257;}s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:59:"s9e\\TextFormatter\\Parser\\FilterProcessing::filterAttributes";s:6:"params";a:4:{s:3:"tag";N;s:9:"tagConfig";N;s:14:"registeredVars";N;s:6:"logger";N;}}}s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3089;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";a:3:{i:0;i:32928;i:1;i:257;i:2;i:256;}}s:1:"C";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:66;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:0;i:1;i:0;i:2;i:0;}}s:4:"CODE";a:7:{s:10:"attributes";a:1:{s:4:"lang";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:23:"/^[- +,.0-9A-Za-z_]+$/D";}}}s:8:"required";b:0;}}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:10:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:4436;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:1;s:7:"allowed";R:282;}s:11:"DAILYMOTION";a:7:{s:10:"attributes";a:2:{s:2:"id";R:257;s:1:"t";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:3:"DEL";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:512;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";R:249;}s:2:"EM";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:2;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65505;i:1;i:65281;i:2;i:257;}}s:5:"EMAIL";a:7:{s:10:"attributes";a:1:{s:5:"email";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:61:"s9e\\TextFormatter\\Parser\\AttributeFilters\\EmailFilter::filter";s:6:"params";a:1:{s:9:"attrValue";N;}}}s:8:"required";b:1;}}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:514;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";a:3:{i:0;i:36743;i:1;i:65329;i:2;i:257;}}s:3:"ESC";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:1616;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:7;s:7:"allowed";R:282;}s:8:"FACEBOOK";a:7:{s:10:"attributes";a:3:{s:2:"id";R:257;s:4:"type";R:257;s:4:"user";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:2:"FP";a:7:{s:10:"attributes";a:1:{s:4:"char";a:2:{s:8:"required";b:1;s:11:"filterChain";R:84;}}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:8;s:7:"allowed";a:3:{i:0;i:32896;i:1;i:257;i:2;i:257;}}s:2:"H1";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:260;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:2;s:7:"allowed";R:329;}s:2:"H2";R:373;s:2:"H3";R:373;s:2:"H4";R:373;s:2:"H5";R:373;s:2:"H6";R:373;s:2:"HC";a:7:{s:10:"attributes";a:1:{s:7:"content";R:364;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3153;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:7;s:7:"allowed";R:282;}s:2:"HE";R:362;s:2:"HR";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:2:{s:11:"closeParent";R:298;s:5:"flags";i:3349;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";R:369;}s:3:"IMG";a:7:{s:10:"attributes";a:3:{s:3:"alt";R:257;s:3:"src";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:59:"s9e\\TextFormatter\\Parser\\AttributeFilters\\UrlFilter::filter";s:6:"params";a:3:{s:9:"attrValue";N;s:9:"urlConfig";N;s:6:"logger";N;}}}s:8:"required";b:1;}s:5:"title";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:369;}s:8:"ISPOILER";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:0;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";R:329;}s:2:"LI";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"LI";i:1;s:7:"html:li";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:264;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:3;s:7:"allowed";a:3:{i:0;i:65527;i:1;i:65313;i:2;i:257;}}s:4:"LIST";a:7:{s:10:"attributes";a:2:{s:5:"start";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:67:"s9e\\TextFormatter\\Parser\\AttributeFilters\\NumericFilter::filterUint";s:6:"params";R:339;}}s:8:"required";b:0;}s:4:"type";R:288;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:3460;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65416;i:1;i:65280;i:2;i:257;}}s:8:"LIVELEAK";a:7:{s:10:"attributes";a:1:{s:2:"id";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:5:"MEDIA";a:7:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:54:"s9e\\TextFormatter\\Plugins\\MediaEmbed\\Parser::filterTag";s:6:"params";a:5:{s:3:"tag";N;s:6:"parser";N;s:16:"MediaEmbed.hosts";N;s:16:"MediaEmbed.sites";N;s:8:"cacheDir";N;}}}s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:513;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:16;s:7:"allowed";a:3:{i:0;i:65527;i:1;i:65329;i:2;i:256;}}s:5:"QUOTE";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:268;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";R:431;}s:10:"SOUNDCLOUD";a:7:{s:10:"attributes";a:4:{s:2:"id";R:257;s:11:"playlist_id";R:257;s:12:"secret_token";R:257;s:8:"track_id";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:7:"SPOILER";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:477;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:4;s:7:"allowed";R:431;}s:7:"SPOTIFY";R:451;s:6:"STRONG";R:323;s:3:"SUB";R:406;s:3:"SUP";R:406;s:5:"TABLE";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:443;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65290;i:2;i:257;}}s:5:"TBODY";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:20:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:5:"TBODY";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:5:"THEAD";i:1;s:2:"TR";i:1;s:10:"html:tbody";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;s:10:"html:thead";i:1;s:7:"html:tr";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:9;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65288;i:2;i:257;}}s:2:"TD";a:7:{s:10:"attributes";a:1:{s:5:"align";a:2:{s:11:"filterChain";a:2:{i:0;a:2:{s:8:"callback";s:10:"strtolower";s:6:"params";R:339;}i:1;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:34:"/^(?:center|justify|left|right)$/D";}}}s:8:"required";b:0;}}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:14:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:431;}s:2:"TH";a:7:{s:10:"attributes";R:530;s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:542;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";a:3:{i:0;i:64499;i:1;i:65313;i:2;i:257;}}s:5:"THEAD";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:9;s:7:"allowed";R:525;}s:2:"TR";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:16:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:2:"TD";i:1;s:2:"TH";i:1;s:2:"TR";i:1;s:7:"html:td";i:1;s:7:"html:th";i:1;s:7:"html:tr";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:3456;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:11;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65284;i:2;i:257;}}s:6:"TWITCH";a:7:{s:10:"attributes";a:4:{s:7:"channel";R:257;s:7:"clip_id";R:257;s:1:"t";R:257;s:8:"video_id";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:3:"URL";a:7:{s:10:"attributes";a:2:{s:5:"title";R:257;s:3:"url";R:394;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:343;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:5;s:7:"allowed";R:347;}s:5:"VIMEO";a:7:{s:10:"attributes";a:2:{s:2:"id";R:257;s:1:"t";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:65:"s9e\\TextFormatter\\Parser\\AttributeFilters\\TimestampFilter::filter";s:6:"params";R:339;}}s:8:"required";b:0;}}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:4:"VINE";R:451;s:7:"YOUTUBE";a:7:{s:10:"attributes";a:3:{s:2:"id";a:2:{s:11:"filterChain";a:1:{i:0;a:2:{s:8:"callback";s:62:"s9e\\TextFormatter\\Parser\\AttributeFilters\\RegexpFilter::filter";s:6:"params";a:2:{s:9:"attrValue";N;i:0;s:19:"/^[-0-9A-Za-z_]+$/D";}}}s:8:"required";b:0;}s:4:"list";R:257;s:1:"t";R:614;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:268;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:6;s:7:"allowed";R:272;}s:9:"html:abbr";a:7:{s:10:"attributes";a:1:{s:5:"title";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:408;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:329;}s:6:"html:b";R:323;s:7:"html:br";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:1:{s:5:"flags";i:3201;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65280;i:2;i:257;}}s:9:"html:code";R:276;s:7:"html:dd";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:dd";i:1;s:7:"html:dt";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:12;s:7:"allowed";R:431;}s:8:"html:del";R:317;s:8:"html:div";a:7:{s:10:"attributes";a:1:{s:5:"class";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:477;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:13;s:7:"allowed";R:249;}s:7:"html:dl";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:443;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";a:3:{i:0;i:65408;i:1;i:65328;i:2;i:257;}}s:7:"html:dt";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:652;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:12;s:7:"allowed";R:565;}s:6:"html:i";R:323;s:8:"html:img";a:7:{s:10:"attributes";a:5:{s:3:"alt";R:257;s:6:"height";R:257;s:3:"src";a:2:{s:11:"filterChain";R:395;s:8:"required";b:0;}s:5:"title";R:257;s:5:"width";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:642;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:646;}s:8:"html:ins";R:317;s:7:"html:li";R:412;s:7:"html:ol";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:443;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";R:447;}s:8:"html:pre";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:276;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:1;s:7:"allowed";R:329;}s:7:"html:rb";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";R:298;s:12:"fosterParent";R:298;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:14;s:7:"allowed";R:329;}s:7:"html:rp";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";a:3:{s:11:"closeParent";a:12:{s:1:"C";i:1;s:2:"EM";i:1;s:6:"STRONG";i:1;s:3:"URL";i:1;s:5:"EMAIL";i:1;s:6:"html:b";i:1;s:9:"html:code";i:1;s:6:"html:i";i:1;s:11:"html:strong";i:1;s:6:"html:u";i:1;s:7:"html:rp";i:1;s:7:"html:rt";i:1;}s:12:"fosterParent";R:298;s:5:"flags";i:256;}s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:15;s:7:"allowed";R:329;}s:7:"html:rt";R:709;s:8:"html:rtc";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:705;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:14;s:7:"allowed";a:3:{i:0;i:65505;i:1;i:65409;i:2;i:257;}}s:9:"html:ruby";a:7:{s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:408;s:8:"tagLimit";i:5000;s:10:"attributes";R:84;s:9:"bitNumber";i:0;s:7:"allowed";a:3:{i:0;i:65505;i:1;i:65473;i:2;i:257;}}s:9:"html:span";a:7:{s:10:"attributes";R:670;s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:408;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:0;s:7:"allowed";R:329;}s:11:"html:strong";R:323;s:8:"html:sub";R:406;s:8:"html:sup";R:406;s:10:"html:table";R:490;s:10:"html:tbody";R:498;s:7:"html:td";a:7:{s:10:"attributes";a:2:{s:7:"colspan";R:257;s:7:"rowspan";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:542;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:431;}s:10:"html:tfoot";R:498;s:7:"html:th";a:7:{s:10:"attributes";a:3:{s:7:"colspan";R:257;s:7:"rowspan";R:257;s:5:"scope";R:257;}s:11:"filterChain";R:259;s:12:"nestingLimit";i:10;s:5:"rules";R:542;s:8:"tagLimit";i:5000;s:9:"bitNumber";i:10;s:7:"allowed";R:565;}s:10:"html:thead";R:569;s:7:"html:tr";R:575;s:6:"html:u";R:323;s:7:"html:ul";R:693;}}');
	}
	public static function getRenderer()
	{
		return unserialize('O:42:"s9e\\TextFormatter\\Bundles\\Fatdown\\Renderer":2:{s:19:"enableQuickRenderer";b:1;s:9:"' . "\0" . '*' . "\0" . 'params";a:0:{}}');
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

						$endTag = $this->parser->addEndTag('CODE', $textBoundary, 0, -1);
						$endTag->pairWith($codeTag);
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
						$endTag = $this->parser->addEndTag('CODE', $tagPos, $tagLen, -1);
						$endTag->pairWith($codeTag);

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
		if (isset($this->emPos) && $this->emPos === $this->strongPos)
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
			$this->emPos = null;
		}
		if ($this->closeStrong)
		{
			$this->remaining -= 2;
			$this->parser->addTagPair('STRONG', $this->strongPos, 2, $this->strongEndPos, 2);
			$this->strongPos = null;
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
		$this->emPos     = null;
		$this->strongPos = null;
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

		$this->closeEm      = ($closeLen & 1) && isset($this->emPos);
		$this->closeStrong  = ($closeLen & 2) && isset($this->strongPos);
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
			str_replace("\x1BB", '\\`', $this->text),
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
	protected function parseInlineLinks()
	{
		preg_match_all(
			'/\\[(?:[^\\x17[\\]]|\\[[^\\x17[\\]]*\\])*\\]\\(( *(?:[^\\x17\\s()]|\\([^\\x17\\s()]*\\))*(?=[ )]) *(?:"[^\\x17]*?"|\'[^\\x17]*?\'|\\([^\\x17)]*\\))? *)\\)/',
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
	protected $quickRenderingTest = '(<[!?])';
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
			$this->out .= htmlspecialchars($root->textContent,0);
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
			preg_replace(
				'(<[eis]>[^<]*</[eis]>)',
				'',
				substr($xml, 1 + strpos($xml, '>'), -4)
			)
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
		try
		{
			if ($this->canQuickRender($xml))
			{
				return $this->renderQuick($xml);
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
	protected $params=[];
	protected function renderNode(\DOMNode $node)
	{
		switch($node->nodeName){case'BANDCAMP':$this->out.='<span data-s9e-mediaembed="bandcamp" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/';if($node->hasAttribute('album_id')){$this->out.='album='.htmlspecialchars($node->getAttribute('album_id'),2);if($node->hasAttribute('track_num'))$this->out.='/t='.htmlspecialchars($node->getAttribute('track_num'),2);}else$this->out.='track='.htmlspecialchars($node->getAttribute('track_id'),2);$this->out.='"></iframe></span></span>';break;case'C':case'html:code':$this->out.='<code>';$this->at($node);$this->out.='</code>';break;case'CODE':$this->out.='<pre><code';if($node->hasAttribute('lang'))$this->out.=' class="language-'.htmlspecialchars($node->getAttribute('lang'),2).'"';$this->out.='>';$this->at($node);$this->out.='</code></pre>';break;case'DAILYMOTION':$this->out.='<span data-s9e-mediaembed="dailymotion" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//www.dailymotion.com/embed/video/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('t'))$this->out.='?start='.htmlspecialchars($node->getAttribute('t'),2);$this->out.='"></iframe></span></span>';break;case'DEL':case'html:del':$this->out.='<del>';$this->at($node);$this->out.='</del>';break;case'EM':$this->out.='<em>';$this->at($node);$this->out.='</em>';break;case'EMAIL':$this->out.='<a href="mailto:'.htmlspecialchars($node->getAttribute('email'),2).'">';$this->at($node);$this->out.='</a>';break;case'ESC':$this->at($node);break;case'FACEBOOK':$this->out.='<iframe data-s9e-mediaembed="facebook" allowfullscreen="" onload="var c=new MessageChannel;c.port1.onmessage=function(e){style.height=e.data+\'px\'};contentWindow.postMessage(\'s9e:init\',\'https://s9e.github.io\',[c.port2])" scrolling="no" src="https://s9e.github.io/iframe/2/facebook.min.html#'.htmlspecialchars($node->getAttribute('type').$node->getAttribute('id'),2).'" style="border:0;height:360px;max-width:640px;width:100%"></iframe>';break;case'FP':case'HE':$this->out.=htmlspecialchars($node->getAttribute('char'),0);break;case'H1':$this->out.='<h1>';$this->at($node);$this->out.='</h1>';break;case'H2':$this->out.='<h2>';$this->at($node);$this->out.='</h2>';break;case'H3':$this->out.='<h3>';$this->at($node);$this->out.='</h3>';break;case'H4':$this->out.='<h4>';$this->at($node);$this->out.='</h4>';break;case'H5':$this->out.='<h5>';$this->at($node);$this->out.='</h5>';break;case'H6':$this->out.='<h6>';$this->at($node);$this->out.='</h6>';break;case'HC':$this->out.='<!--'.htmlspecialchars($node->getAttribute('content'),0).'-->';break;case'HR':$this->out.='<hr>';break;case'IMG':$this->out.='<img src="'.htmlspecialchars($node->getAttribute('src'),2).'"';if($node->hasAttribute('alt'))$this->out.=' alt="'.htmlspecialchars($node->getAttribute('alt'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';break;case'ISPOILER':$this->out.='<span class="spoiler" onclick="removeAttribute(\'style\')" style="background:#444;color:transparent">';$this->at($node);$this->out.='</span>';break;case'LI':case'html:li':$this->out.='<li>';$this->at($node);$this->out.='</li>';break;case'LIST':if(!$node->hasAttribute('type')){$this->out.='<ul>';$this->at($node);$this->out.='</ul>';}else{$this->out.='<ol';if($node->hasAttribute('start'))$this->out.=' start="'.htmlspecialchars($node->getAttribute('start'),2).'"';$this->out.='>';$this->at($node);$this->out.='</ol>';}break;case'LIVELEAK':$this->out.='<span data-s9e-mediaembed="liveleak" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" src="//www.liveleak.com/e/'.htmlspecialchars($node->getAttribute('id'),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'QUOTE':$this->out.='<blockquote>';$this->at($node);$this->out.='</blockquote>';break;case'SOUNDCLOUD':$this->out.='<iframe data-s9e-mediaembed="soundcloud" allowfullscreen="" scrolling="no" src="https://w.soundcloud.com/player/?url=';if($node->hasAttribute('playlist_id'))$this->out.='https%3A//api.soundcloud.com/playlists/'.htmlspecialchars($node->getAttribute('playlist_id'),2);elseif($node->hasAttribute('track_id'))$this->out.='https%3A//api.soundcloud.com/tracks/'.htmlspecialchars($node->getAttribute('track_id'),2).'&amp;secret_token='.htmlspecialchars($node->getAttribute('secret_token'),2);else{if((strpos($node->getAttribute('id'),'://')===false))$this->out.='https%3A//soundcloud.com/';$this->out.=htmlspecialchars($node->getAttribute('id'),2);}$this->out.='" style="border:0;height:';if($node->hasAttribute('playlist_id')||(strpos($node->getAttribute('id'),'/sets/')!==false))$this->out.='450';else$this->out.='166';$this->out.='px;max-width:900px;width:100%"></iframe>';break;case'SPOILER':$this->out.='<details class="spoiler">';$this->at($node);$this->out.='</details>';break;case'SPOTIFY':$this->out.='<span data-s9e-mediaembed="spotify" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allow="encrypted-media" allowfullscreen="" scrolling="no" src="https://open.spotify.com/embed/'.htmlspecialchars(strtr($node->getAttribute('id'),':','/').$node->getAttribute('path'),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'STRONG':case'html:strong':$this->out.='<strong>';$this->at($node);$this->out.='</strong>';break;case'SUB':case'html:sub':$this->out.='<sub>';$this->at($node);$this->out.='</sub>';break;case'SUP':case'html:sup':$this->out.='<sup>';$this->at($node);$this->out.='</sup>';break;case'TABLE':case'html:table':$this->out.='<table>';$this->at($node);$this->out.='</table>';break;case'TBODY':case'html:tbody':$this->out.='<tbody>';$this->at($node);$this->out.='</tbody>';break;case'TD':$this->out.='<td';if($node->hasAttribute('align'))$this->out.=' style="text-align:'.htmlspecialchars($node->getAttribute('align'),2).'"';$this->out.='>';$this->at($node);$this->out.='</td>';break;case'TH':$this->out.='<th';if($node->hasAttribute('align'))$this->out.=' style="text-align:'.htmlspecialchars($node->getAttribute('align'),2).'"';$this->out.='>';$this->at($node);$this->out.='</th>';break;case'THEAD':case'html:thead':$this->out.='<thead>';$this->at($node);$this->out.='</thead>';break;case'TR':case'html:tr':$this->out.='<tr>';$this->at($node);$this->out.='</tr>';break;case'TWITCH':$this->out.='<span data-s9e-mediaembed="twitch" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//';if($node->hasAttribute('clip_id')){$this->out.='clips.twitch.tv/embed?autoplay=false&amp;clip=';if($node->hasAttribute('channel'))$this->out.=htmlspecialchars($node->getAttribute('channel'),2).'/';$this->out.=htmlspecialchars($node->getAttribute('clip_id'),2);}else{$this->out.='player.twitch.tv/?autoplay=false&amp;';if($node->hasAttribute('video_id'))$this->out.='video=v'.htmlspecialchars($node->getAttribute('video_id'),2);else$this->out.='channel='.htmlspecialchars($node->getAttribute('channel'),2);if($node->hasAttribute('t'))$this->out.='&amp;time='.htmlspecialchars($node->getAttribute('t'),2);}$this->out.='"></iframe></span></span>';break;case'URL':$this->out.='<a href="'.htmlspecialchars($node->getAttribute('url'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';$this->at($node);$this->out.='</a>';break;case'VIMEO':$this->out.='<span data-s9e-mediaembed="vimeo" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//player.vimeo.com/video/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('t'))$this->out.='#t='.htmlspecialchars($node->getAttribute('t'),2);$this->out.='"></iframe></span></span>';break;case'VINE':$this->out.='<span data-s9e-mediaembed="vine" style="display:inline-block;width:100%;max-width:480px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" scrolling="no" src="https://vine.co/v/'.htmlspecialchars($node->getAttribute('id'),2).'/embed/simple?audio=1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'YOUTUBE':$this->out.='<span data-s9e-mediaembed="youtube" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="background:url(https://i.ytimg.com/vi/'.htmlspecialchars($node->getAttribute('id'),2).'/hqdefault.jpg) 50% 50% / cover;border:0;height:100%;left:0;position:absolute;width:100%" src="https://www.youtube.com/embed/'.htmlspecialchars($node->getAttribute('id'),2);if($node->hasAttribute('list'))$this->out.='?list='.htmlspecialchars($node->getAttribute('list'),2);if($node->hasAttribute('t')){if($node->hasAttribute('list'))$this->out.='&amp;';else$this->out.='?';$this->out.='start='.htmlspecialchars($node->getAttribute('t'),2);}$this->out.='"></iframe></span></span>';break;case'br':case'html:br':$this->out.='<br>';break;case'e':case'i':case's':break;case'html:abbr':$this->out.='<abbr';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';$this->out.='>';$this->at($node);$this->out.='</abbr>';break;case'html:b':$this->out.='<b>';$this->at($node);$this->out.='</b>';break;case'html:dd':$this->out.='<dd>';$this->at($node);$this->out.='</dd>';break;case'html:div':$this->out.='<div';if($node->hasAttribute('class'))$this->out.=' class="'.htmlspecialchars($node->getAttribute('class'),2).'"';$this->out.='>';$this->at($node);$this->out.='</div>';break;case'html:dl':$this->out.='<dl>';$this->at($node);$this->out.='</dl>';break;case'html:dt':$this->out.='<dt>';$this->at($node);$this->out.='</dt>';break;case'html:i':$this->out.='<i>';$this->at($node);$this->out.='</i>';break;case'html:img':$this->out.='<img';if($node->hasAttribute('alt'))$this->out.=' alt="'.htmlspecialchars($node->getAttribute('alt'),2).'"';if($node->hasAttribute('height'))$this->out.=' height="'.htmlspecialchars($node->getAttribute('height'),2).'"';if($node->hasAttribute('src'))$this->out.=' src="'.htmlspecialchars($node->getAttribute('src'),2).'"';if($node->hasAttribute('title'))$this->out.=' title="'.htmlspecialchars($node->getAttribute('title'),2).'"';if($node->hasAttribute('width'))$this->out.=' width="'.htmlspecialchars($node->getAttribute('width'),2).'"';$this->out.='>';break;case'html:ins':$this->out.='<ins>';$this->at($node);$this->out.='</ins>';break;case'html:ol':$this->out.='<ol>';$this->at($node);$this->out.='</ol>';break;case'html:pre':$this->out.='<pre>';$this->at($node);$this->out.='</pre>';break;case'html:rb':$this->out.='<rb>';$this->at($node);$this->out.='</rb>';break;case'html:rp':$this->out.='<rp>';$this->at($node);$this->out.='</rp>';break;case'html:rt':$this->out.='<rt>';$this->at($node);$this->out.='</rt>';break;case'html:rtc':$this->out.='<rtc>';$this->at($node);$this->out.='</rtc>';break;case'html:ruby':$this->out.='<ruby>';$this->at($node);$this->out.='</ruby>';break;case'html:span':$this->out.='<span';if($node->hasAttribute('class'))$this->out.=' class="'.htmlspecialchars($node->getAttribute('class'),2).'"';$this->out.='>';$this->at($node);$this->out.='</span>';break;case'html:td':$this->out.='<td';if($node->hasAttribute('colspan'))$this->out.=' colspan="'.htmlspecialchars($node->getAttribute('colspan'),2).'"';if($node->hasAttribute('rowspan'))$this->out.=' rowspan="'.htmlspecialchars($node->getAttribute('rowspan'),2).'"';$this->out.='>';$this->at($node);$this->out.='</td>';break;case'html:tfoot':$this->out.='<tfoot>';$this->at($node);$this->out.='</tfoot>';break;case'html:th':$this->out.='<th';if($node->hasAttribute('colspan'))$this->out.=' colspan="'.htmlspecialchars($node->getAttribute('colspan'),2).'"';if($node->hasAttribute('rowspan'))$this->out.=' rowspan="'.htmlspecialchars($node->getAttribute('rowspan'),2).'"';if($node->hasAttribute('scope'))$this->out.=' scope="'.htmlspecialchars($node->getAttribute('scope'),2).'"';$this->out.='>';$this->at($node);$this->out.='</th>';break;case'html:u':$this->out.='<u>';$this->at($node);$this->out.='</u>';break;case'html:ul':$this->out.='<ul>';$this->at($node);$this->out.='</ul>';break;case'p':$this->out.='<p>';$this->at($node);$this->out.='</p>';break;default:$this->at($node);}
	}
	public $enableQuickRenderer=true;
	protected $static=['/C'=>'</code>','/CODE'=>'</code></pre>','/DEL'=>'</del>','/EM'=>'</em>','/EMAIL'=>'</a>','/ESC'=>'','/H1'=>'</h1>','/H2'=>'</h2>','/H3'=>'</h3>','/H4'=>'</h4>','/H5'=>'</h5>','/H6'=>'</h6>','/ISPOILER'=>'</span>','/LI'=>'</li>','/QUOTE'=>'</blockquote>','/SPOILER'=>'</details>','/STRONG'=>'</strong>','/SUB'=>'</sub>','/SUP'=>'</sup>','/TABLE'=>'</table>','/TBODY'=>'</tbody>','/TD'=>'</td>','/TH'=>'</th>','/THEAD'=>'</thead>','/TR'=>'</tr>','/URL'=>'</a>','/html:abbr'=>'</abbr>','/html:b'=>'</b>','/html:code'=>'</code>','/html:dd'=>'</dd>','/html:del'=>'</del>','/html:div'=>'</div>','/html:dl'=>'</dl>','/html:dt'=>'</dt>','/html:i'=>'</i>','/html:ins'=>'</ins>','/html:li'=>'</li>','/html:ol'=>'</ol>','/html:pre'=>'</pre>','/html:rb'=>'</rb>','/html:rp'=>'</rp>','/html:rt'=>'</rt>','/html:rtc'=>'</rtc>','/html:ruby'=>'</ruby>','/html:span'=>'</span>','/html:strong'=>'</strong>','/html:sub'=>'</sub>','/html:sup'=>'</sup>','/html:table'=>'</table>','/html:tbody'=>'</tbody>','/html:td'=>'</td>','/html:tfoot'=>'</tfoot>','/html:th'=>'</th>','/html:thead'=>'</thead>','/html:tr'=>'</tr>','/html:u'=>'</u>','/html:ul'=>'</ul>','C'=>'<code>','DEL'=>'<del>','EM'=>'<em>','ESC'=>'','H1'=>'<h1>','H2'=>'<h2>','H3'=>'<h3>','H4'=>'<h4>','H5'=>'<h5>','H6'=>'<h6>','HR'=>'<hr>','ISPOILER'=>'<span class="spoiler" onclick="removeAttribute(\'style\')" style="background:#444;color:transparent">','LI'=>'<li>','QUOTE'=>'<blockquote>','SPOILER'=>'<details class="spoiler">','STRONG'=>'<strong>','SUB'=>'<sub>','SUP'=>'<sup>','TABLE'=>'<table>','TBODY'=>'<tbody>','THEAD'=>'<thead>','TR'=>'<tr>','html:b'=>'<b>','html:br'=>'<br>','html:code'=>'<code>','html:dd'=>'<dd>','html:del'=>'<del>','html:dl'=>'<dl>','html:dt'=>'<dt>','html:i'=>'<i>','html:ins'=>'<ins>','html:li'=>'<li>','html:ol'=>'<ol>','html:pre'=>'<pre>','html:rb'=>'<rb>','html:rp'=>'<rp>','html:rt'=>'<rt>','html:rtc'=>'<rtc>','html:ruby'=>'<ruby>','html:strong'=>'<strong>','html:sub'=>'<sub>','html:sup'=>'<sup>','html:table'=>'<table>','html:tbody'=>'<tbody>','html:tfoot'=>'<tfoot>','html:thead'=>'<thead>','html:tr'=>'<tr>','html:u'=>'<u>','html:ul'=>'<ul>'];
	protected $dynamic=['EMAIL'=>['(^[^ ]+(?> (?!email=)[^=]+="[^"]*")*(?> email="([^"]*)")?.*)s','<a href="mailto:$1">'],'IMG'=>['(^[^ ]+(?> (?!(?:alt|src|title)=)[^=]+="[^"]*")*( alt="[^"]*")?(?> (?!(?:src|title)=)[^=]+="[^"]*")*(?> src="([^"]*)")?(?> (?!title=)[^=]+="[^"]*")*( title="[^"]*")?.*)s','<img src="$2"$1$3>'],'LIVELEAK'=>['(^[^ ]+(?> (?!id=)[^=]+="[^"]*")*(?> id="([^"]*)")?.*)s','<span data-s9e-mediaembed="liveleak" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" src="//www.liveleak.com/e/$1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>'],'URL'=>['(^[^ ]+(?> (?!(?:title|url)=)[^=]+="[^"]*")*( title="[^"]*")?(?> (?!url=)[^=]+="[^"]*")*(?> url="([^"]*)")?.*)s','<a href="$2"$1>'],'VINE'=>['(^[^ ]+(?> (?!id=)[^=]+="[^"]*")*(?> id="([^"]*)")?.*)s','<span data-s9e-mediaembed="vine" style="display:inline-block;width:100%;max-width:480px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" scrolling="no" src="https://vine.co/v/$1/embed/simple?audio=1" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>'],'html:abbr'=>['(^[^ ]+(?> (?!title=)[^=]+="[^"]*")*( title="[^"]*")?.*)s','<abbr$1>'],'html:div'=>['(^[^ ]+(?> (?!class=)[^=]+="[^"]*")*( class="[^"]*")?.*)s','<div$1>'],'html:img'=>['(^[^ ]+(?> (?!(?:alt|height|src|title|width)=)[^=]+="[^"]*")*( alt="[^"]*")?(?> (?!(?:height|src|title|width)=)[^=]+="[^"]*")*( height="[^"]*")?(?> (?!(?:src|title|width)=)[^=]+="[^"]*")*( src="[^"]*")?(?> (?!(?:title|width)=)[^=]+="[^"]*")*( title="[^"]*")?(?> (?!width=)[^=]+="[^"]*")*( width="[^"]*")?.*)s','<img$1$2$3$4$5>'],'html:span'=>['(^[^ ]+(?> (?!class=)[^=]+="[^"]*")*( class="[^"]*")?.*)s','<span$1>'],'html:td'=>['(^[^ ]+(?> (?!(?:col|row)span=)[^=]+="[^"]*")*( colspan="[^"]*")?(?> (?!rowspan=)[^=]+="[^"]*")*( rowspan="[^"]*")?.*)s','<td$1$2>'],'html:th'=>['(^[^ ]+(?> (?!(?:colspan|rowspan|scope)=)[^=]+="[^"]*")*( colspan="[^"]*")?(?> (?!(?:rowspan|scope)=)[^=]+="[^"]*")*( rowspan="[^"]*")?(?> (?!scope=)[^=]+="[^"]*")*( scope="[^"]*")?.*)s','<th$1$2$3>']];
	protected $quickRegexp='(<(?:(?!/)((?:BANDCAMP|DAILYMOTION|F(?:ACEBOOK|P)|H[CER]|IMG|LIVELEAK|S(?:OUNDCLOUD|POTIFY)|TWITCH|VI(?:MEO|NE)|YOUTUBE|html:(?:br|img)))(?: [^>]*)?>.*?</\\1|(/?(?!br/|p>)[^ />]+)[^>]*?(/)?)>)s';
	protected function renderQuickTemplate($id, $xml)
	{
		$attributes=$this->matchAttributes($xml);
		$html='';switch($id){case'/LIST':$attributes=array_pop($this->attributes);if(!isset($attributes['type']))$html.='</ul>';else$html.='</ol>';break;case'BANDCAMP':$attributes+=['track_num'=>null,'track_id'=>null];$html.='<span data-s9e-mediaembed="bandcamp" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//bandcamp.com/EmbeddedPlayer/size=large/minimal=true/';if(isset($attributes['album_id'])){$html.='album='.$attributes['album_id'];if(isset($attributes['track_num']))$html.='/t='.$attributes['track_num'];}else$html.='track='.$attributes['track_id'];$html.='"></iframe></span></span>';break;case'CODE':$html.='<pre><code';if(isset($attributes['lang']))$html.=' class="language-'.$attributes['lang'].'"';$html.='>';break;case'DAILYMOTION':$attributes+=['id'=>null];$html.='<span data-s9e-mediaembed="dailymotion" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//www.dailymotion.com/embed/video/'.$attributes['id'];if(isset($attributes['t']))$html.='?start='.$attributes['t'];$html.='"></iframe></span></span>';break;case'FACEBOOK':$attributes+=['type'=>null,'id'=>null];$html.='<iframe data-s9e-mediaembed="facebook" allowfullscreen="" onload="var c=new MessageChannel;c.port1.onmessage=function(e){style.height=e.data+\'px\'};contentWindow.postMessage(\'s9e:init\',\'https://s9e.github.io\',[c.port2])" scrolling="no" src="https://s9e.github.io/iframe/2/facebook.min.html#'.$attributes['type'].$attributes['id'].'" style="border:0;height:360px;max-width:640px;width:100%"></iframe>';break;case'FP':case'HE':$attributes+=['char'=>null];$html.=str_replace('&quot;','"',$attributes['char']);break;case'HC':$attributes+=['content'=>null];$html.='<!--'.str_replace('&quot;','"',$attributes['content']).'-->';break;case'LIST':if(!isset($attributes['type']))$html.='<ul>';else{$html.='<ol';if(isset($attributes['start']))$html.=' start="'.$attributes['start'].'"';$html.='>';}$this->attributes[]=$attributes;break;case'SOUNDCLOUD':$attributes+=['secret_token'=>null,'id'=>null];$html.='<iframe data-s9e-mediaembed="soundcloud" allowfullscreen="" scrolling="no" src="https://w.soundcloud.com/player/?url=';if(isset($attributes['playlist_id']))$html.='https%3A//api.soundcloud.com/playlists/'.$attributes['playlist_id'];elseif(isset($attributes['track_id']))$html.='https%3A//api.soundcloud.com/tracks/'.$attributes['track_id'].'&amp;secret_token='.$attributes['secret_token'];else{if((strpos($attributes['id'],'://')===false))$html.='https%3A//soundcloud.com/';$html.=$attributes['id'];}$html.='" style="border:0;height:';if(isset($attributes['playlist_id'])||(strpos($attributes['id'],'/sets/')!==false))$html.='450';else$html.='166';$html.='px;max-width:900px;width:100%"></iframe>';break;case'SPOTIFY':$attributes+=['id'=>null,'path'=>null];$html.='<span data-s9e-mediaembed="spotify" style="display:inline-block;width:100%;max-width:400px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:100%"><iframe allow="encrypted-media" allowfullscreen="" scrolling="no" src="https://open.spotify.com/embed/'.htmlspecialchars(strtr(htmlspecialchars_decode($attributes['id']),':','/').htmlspecialchars_decode($attributes['path']),2).'" style="border:0;height:100%;left:0;position:absolute;width:100%"></iframe></span></span>';break;case'TD':$html.='<td';if(isset($attributes['align']))$html.=' style="text-align:'.$attributes['align'].'"';$html.='>';break;case'TH':$html.='<th';if(isset($attributes['align']))$html.=' style="text-align:'.$attributes['align'].'"';$html.='>';break;case'TWITCH':$attributes+=['channel'=>null,'clip_id'=>null];$html.='<span data-s9e-mediaembed="twitch" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//';if(isset($attributes['clip_id'])){$html.='clips.twitch.tv/embed?autoplay=false&amp;clip=';if(isset($attributes['channel']))$html.=$attributes['channel'].'/';$html.=$attributes['clip_id'];}else{$html.='player.twitch.tv/?autoplay=false&amp;';if(isset($attributes['video_id']))$html.='video=v'.$attributes['video_id'];else$html.='channel='.$attributes['channel'];if(isset($attributes['t']))$html.='&amp;time='.$attributes['t'];}$html.='"></iframe></span></span>';break;case'VIMEO':$attributes+=['id'=>null];$html.='<span data-s9e-mediaembed="vimeo" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="border:0;height:100%;left:0;position:absolute;width:100%" src="//player.vimeo.com/video/'.$attributes['id'];if(isset($attributes['t']))$html.='#t='.$attributes['t'];$html.='"></iframe></span></span>';break;case'YOUTUBE':$attributes+=['id'=>null,'t'=>null];$html.='<span data-s9e-mediaembed="youtube" style="display:inline-block;width:100%;max-width:640px"><span style="display:block;overflow:hidden;position:relative;padding-bottom:56.25%"><iframe allowfullscreen="" scrolling="no" style="background:url(https://i.ytimg.com/vi/'.$attributes['id'].'/hqdefault.jpg) 50% 50% / cover;border:0;height:100%;left:0;position:absolute;width:100%" src="https://www.youtube.com/embed/'.$attributes['id'];if(isset($attributes['list']))$html.='?list='.$attributes['list'];if(isset($attributes['t'])){if(isset($attributes['list']))$html.='&amp;';else$html.='?';$html.='start='.$attributes['t'];}$html.='"></iframe></span></span>';}

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
		$this->parseAbstractScript('SUB', '~', '/~(?!\\()[^\\x17\\s~()]++~?/', '/~\\([^\\x17()]+\\)/');
	}
}
namespace s9e\TextFormatter\Plugins\Litedown\Parser\Passes;

class Superscript extends AbstractScript
{
	public function parse()
	{
		$this->parseAbstractScript('SUP', '^', '/\\^(?!\\()[^\\x17\\s^()]++\\^?/', '/\\^\\([^\\x17()]++\\)/');
	}
}
namespace s9e\TextFormatter;
const VERSION = '2.1.0';
