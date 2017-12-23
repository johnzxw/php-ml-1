<?php declare(strict_types = 1);

namespace SlevomatCodingStandard\Helpers;

class YodaHelper
{

	const DYNAMISM_VARIABLE = 999;

	const DYNAMISM_CONSTANT = 1;

	const DYNAMISM_FUNCTION_CALL = self::DYNAMISM_VARIABLE;

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param mixed[] $leftSideTokens
	 * @param mixed[] $rightSideTokens
	 */
	public static function fix(\PHP_CodeSniffer\Files\File $phpcsFile, array $leftSideTokens, array $rightSideTokens)
	{
		$phpcsFile->fixer->beginChangeset();
		self::replace($phpcsFile, $leftSideTokens, $rightSideTokens);
		self::replace($phpcsFile, $rightSideTokens, $leftSideTokens);
		$phpcsFile->fixer->endChangeset();
	}

	/**
	 * @param \PHP_CodeSniffer\Files\File $phpcsFile
	 * @param mixed[] $leftSideTokens
	 * @param mixed[] $rightSideTokens
	 */
	private static function replace(\PHP_CodeSniffer\Files\File $phpcsFile, array $leftSideTokens, array $rightSideTokens)
	{
		current($leftSideTokens);
		$firstLeftPointer = key($leftSideTokens);
		end($leftSideTokens);
		$lastLeftPointer = key($leftSideTokens);

		for ($i = $firstLeftPointer; $i <= $lastLeftPointer; $i++) {
			$phpcsFile->fixer->replaceToken($i, '');
		}

		$phpcsFile->fixer->addContent($firstLeftPointer, implode('', array_map(function (array $token): string {
			return $token['content'];
		}, $rightSideTokens)));
	}

	/**
	 * @param mixed[] $tokens
	 * @param int $comparisonTokenPointer
	 * @return mixed[]
	 */
	public static function getLeftSideTokens(array $tokens, int $comparisonTokenPointer): array
	{
		$parenthesisDepth = 0;
		$shortArrayDepth = 0;
		$examinedTokenPointer = $comparisonTokenPointer;
		$sideTokens = [];
		$stopTokenCodes = self::getStopTokenCodes();
		while (true) {
			$examinedTokenPointer--;
			$examinedToken = $tokens[$examinedTokenPointer];
			if ($parenthesisDepth === 0 && isset($stopTokenCodes[$examinedToken['code']])) {
				break;
			}

			if ($examinedToken['code'] === T_CLOSE_SHORT_ARRAY) {
				$shortArrayDepth++;
			} elseif ($examinedToken['code'] === T_OPEN_SHORT_ARRAY) {
				if ($shortArrayDepth === 0) {
					break;
				}

				$shortArrayDepth--;
			}

			if ($examinedToken['code'] === T_CLOSE_PARENTHESIS) {
				$parenthesisDepth++;
			} elseif ($examinedToken['code'] === T_OPEN_PARENTHESIS) {
				if ($parenthesisDepth === 0) {
					break;
				}

				$parenthesisDepth--;
			}

			$sideTokens[$examinedTokenPointer] = $examinedToken;
		}

		return self::trimWhitespaceTokens(array_reverse($sideTokens, true));
	}

	/**
	 * @param mixed[] $tokens
	 * @param int $comparisonTokenPointer
	 * @return mixed[]
	 */
	public static function getRightSideTokens(array $tokens, int $comparisonTokenPointer): array
	{
		$parenthesisDepth = 0;
		$shortArrayDepth = 0;
		$examinedTokenPointer = $comparisonTokenPointer;
		$sideTokens = [];
		$stopTokenCodes = self::getStopTokenCodes();
		while (true) {
			$examinedTokenPointer++;
			$examinedToken = $tokens[$examinedTokenPointer];
			if ($parenthesisDepth === 0 && isset($stopTokenCodes[$examinedToken['code']])) {
				break;
			}

			if ($examinedToken['code'] === T_OPEN_SHORT_ARRAY) {
				$shortArrayDepth++;
			} elseif ($examinedToken['code'] === T_CLOSE_SHORT_ARRAY) {
				if ($shortArrayDepth === 0) {
					break;
				}

				$shortArrayDepth--;
			}

			if ($examinedToken['code'] === T_OPEN_PARENTHESIS) {
				$parenthesisDepth++;
			} elseif ($examinedToken['code'] === T_CLOSE_PARENTHESIS) {
				if ($parenthesisDepth === 0) {
					break;
				}

				$parenthesisDepth--;
			}

			$sideTokens[$examinedTokenPointer] = $examinedToken;
		}

		return self::trimWhitespaceTokens($sideTokens);
	}

	/**
	 * @param mixed[] $tokens
	 * @param mixed[] $sideTokens
	 * @return int|null
	 */
	public static function getDynamismForTokens(array $tokens, array $sideTokens)
	{
		$sideTokens = array_values(array_filter($sideTokens, function (array $token): bool {
			return !in_array($token['code'], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_NS_SEPARATOR, T_PLUS, T_MINUS, T_INT_CAST, T_DOUBLE_CAST, T_STRING_CAST, T_ARRAY_CAST, T_OBJECT_CAST, T_BOOL_CAST, T_UNSET_CAST], true);
		}));

		$sideTokensCount = count($sideTokens);

		$dynamism = self::getTokenDynamism();

		if ($sideTokensCount > 0) {
			if ($sideTokens[0]['code'] === T_VARIABLE) {
				// expression starts with a variable - wins over everything else
				return self::DYNAMISM_VARIABLE;
			} elseif ($sideTokens[$sideTokensCount - 1]['code'] === T_CLOSE_PARENTHESIS) {
				if (isset($sideTokens[$sideTokensCount - 1]['parenthesis_owner']) && $tokens[$sideTokens[$sideTokensCount - 1]['parenthesis_owner']]['code'] === T_ARRAY) {
					// array()
					return $dynamism[T_ARRAY];
				} else {
					// function or method call
					return self::DYNAMISM_FUNCTION_CALL;
				}
			} elseif ($sideTokensCount === 1 && $sideTokens[0]['code'] === T_STRING) {
				// constant
				return self::DYNAMISM_CONSTANT;
			}
		}

		if ($sideTokensCount > 2 && $sideTokens[$sideTokensCount - 2]['code'] === T_DOUBLE_COLON) {
			if ($sideTokens[$sideTokensCount - 1]['code'] === T_VARIABLE) {
				// static property access
				return self::DYNAMISM_VARIABLE;
			} elseif ($sideTokens[$sideTokensCount - 1]['code'] === T_STRING) {
				// class constant
				return self::DYNAMISM_CONSTANT;
			}
		}

		if (isset($sideTokens[0]) && isset($dynamism[$sideTokens[0]['code']])) {
			return $dynamism[$sideTokens[0]['code']];
		}

		return null;
	}

	/**
	 * @param mixed[] $tokens
	 * @return mixed[]
	 */
	public static function trimWhitespaceTokens(array $tokens): array
	{
		foreach ($tokens as $pointer => $token) {
			if ($token['code'] === T_WHITESPACE) {
				unset($tokens[$pointer]);
			} else {
				break;
			}
		}

		foreach (array_reverse($tokens, true) as $pointer => $token) {
			if ($token['code'] === T_WHITESPACE) {
				unset($tokens[$pointer]);
			} else {
				break;
			}
		}

		return $tokens;
	}

	/**
	 * @return int[]
	 */
	private static function getTokenDynamism(): array
	{
		static $tokenDynamism;

		if ($tokenDynamism === null) {
			$tokenDynamism = [
				T_TRUE => 0,
				T_FALSE => 0,
				T_NULL => 0,
				T_DNUMBER => 0,
				T_LNUMBER => 0,
				T_OPEN_SHORT_ARRAY => 0,
				T_ARRAY => 0, // do not stack error messages when the old-style array syntax is used
				T_CONSTANT_ENCAPSED_STRING => 0,
				T_VARIABLE => self::DYNAMISM_VARIABLE,
				T_STRING => self::DYNAMISM_FUNCTION_CALL,
			];

			$tokenDynamism += array_fill_keys(array_keys(\PHP_CodeSniffer\Util\Tokens::$castTokens), 3);
		}

		return $tokenDynamism;
	}

	/**
	 * @return bool[]
	 */
	private static function getStopTokenCodes(): array
	{
		static $stopTokenCodes;

		if ($stopTokenCodes === null) {
			$stopTokenCodes = [
				T_BOOLEAN_AND => true,
				T_BOOLEAN_OR => true,
				T_SEMICOLON => true,
				T_OPEN_TAG => true,
				T_INLINE_THEN => true,
				T_INLINE_ELSE => true,
				T_LOGICAL_AND => true,
				T_LOGICAL_OR => true,
				T_LOGICAL_XOR => true,
				T_COALESCE => true,
				T_CASE => true,
				T_COLON => true,
				T_RETURN => true,
				T_COMMA => true,
				T_CLOSE_CURLY_BRACKET => true,
			];

			$stopTokenCodes += array_fill_keys(array_keys(\PHP_CodeSniffer\Util\Tokens::$assignmentTokens), true);
		}

		return $stopTokenCodes;
	}

}
