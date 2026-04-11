<?php
/**
 * Readability scoring: Flesch, sentence length, passive voice, transition words, paragraph distribution.
 *
 * @package Meyvora_SEO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Meyvora_SEO_Readability {

	/**
	 * Calculate Flesch Reading Ease score (0–100+; 60+ = plain English).
	 *
	 * @param string $text Plain text.
	 * @return float
	 */
	public static function calculate_flesch_reading_ease( string $text ): float {
		$sentences = self::count_sentences( $text );
		$words = self::count_words( $text );
		if ( $sentences < 1 || $words < 1 ) {
			return 0.0;
		}
		$syllables = 0;
		$word_list = preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $word_list as $word ) {
			$syllables += self::count_syllables( $word );
		}
		$avg_syllables_per_word = $syllables / $words;
		$avg_words_per_sentence = $words / $sentences;
		return 206.835 - ( 1.015 * $avg_words_per_sentence ) - ( 84.6 * $avg_syllables_per_word );
	}

	/**
	 * Approximate syllables per word (English).
	 *
	 * @param string $word Single word.
	 * @return int
	 */
	public static function count_syllables( string $word ): int {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( $word === '' ) {
			return 0;
		}
		$vowels = 'aeiouy';
		$count = 0;
		$prev_vowel = false;
		for ( $i = 0; $i < strlen( $word ); $i++ ) {
			$is_vowel = strpos( $vowels, $word[ $i ] ) !== false;
			if ( $is_vowel && ! $prev_vowel ) {
				$count++;
			}
			$prev_vowel = $is_vowel;
		}
		if ( substr( $word, -1 ) === 'e' && $count > 1 ) {
			$count--;
		}
		return max( 1, $count );
	}

	/**
	 * Passive voice as percentage of sentences (heuristic: "was/were/been + past participle").
	 *
	 * @param string $text Plain text.
	 * @return float 0–100
	 */
	public static function get_passive_voice_percentage( string $text ): float {
		$sentences = self::get_sentences( $text );
		if ( empty( $sentences ) ) {
			return 0.0;
		}
		$passive = 0;
		$pattern = '/\b(was|were|been|be)\s+[\w\s]+(?:ed|en|t)\b/i';
		foreach ( $sentences as $s ) {
			if ( preg_match( $pattern, $s ) ) {
				$passive++;
			}
		}
		return ( $passive / count( $sentences ) ) * 100;
	}

	/**
	 * Percentage of sentences that start with or contain common transition words.
	 *
	 * @param string $text Plain text.
	 * @return float 0–100
	 */
	public static function get_transition_word_percentage( string $text ): float {
		$transitions = array( 'however', 'therefore', 'furthermore', 'additionally', 'meanwhile', 'consequently', 'finally', 'first', 'second', 'also', 'then', 'next', 'for example', 'in fact', 'in conclusion', 'as a result' );
		$sentences = self::get_sentences( $text );
		if ( empty( $sentences ) ) {
			return 0.0;
		}
		$with_transition = 0;
		foreach ( $sentences as $s ) {
			$s_lower = strtolower( $s );
			foreach ( $transitions as $t ) {
				if ( strpos( $s_lower, $t ) !== false ) {
					$with_transition++;
					break;
				}
			}
		}
		return ( $with_transition / count( $sentences ) ) * 100;
	}

	/**
	 * Average words per sentence.
	 *
	 * @param string $text Plain text.
	 * @return float
	 */
	public static function get_average_sentence_length( string $text ): float {
		$sentences = self::get_sentences( $text );
		if ( empty( $sentences ) ) {
			return 0.0;
		}
		$total = 0;
		foreach ( $sentences as $s ) {
			$total += self::count_words( $s );
		}
		return $total / count( $sentences );
	}

	private static function count_sentences( string $text ): int {
		return count( self::get_sentences( $text ) );
	}

	/**
	 * @return array<int, string>
	 */
	private static function get_sentences( string $text ): array {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/[.!?]+/', "\n", $text );
		$parts = explode( "\n", $text );
		$out = array();
		foreach ( $parts as $p ) {
			$p = trim( $p );
			if ( $p !== '' ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	private static function count_words( string $text ): int {
		return max( 0, str_word_count( $text, 0 ) );
	}

	/**
	 * Compute overall readability score 0–100 from Flesch, sentence length, passive, transition.
	 *
	 * @param string $text Plain text.
	 * @return array{ score: int, flesch: float, avg_sentence: float, passive_pct: float, transition_pct: float }
	 */
	public static function analyze( string $text ): array {
		$text = wp_strip_all_tags( $text );
		$flesch = self::calculate_flesch_reading_ease( $text );
		$avg_sentence = self::get_average_sentence_length( $text );
		$passive_pct = self::get_passive_voice_percentage( $text );
		$transition_pct = self::get_transition_word_percentage( $text );
		$score = 50;
		if ( $flesch >= 60 ) {
			$score += 15;
		} elseif ( $flesch >= 45 ) {
			$score += 5;
		}
		if ( $avg_sentence <= 25 && $avg_sentence > 0 ) {
			$score += 10;
		} elseif ( $avg_sentence <= 35 ) {
			$score += 5;
		}
		if ( $passive_pct < 10 ) {
			$score += 10;
		} elseif ( $passive_pct < 20 ) {
			$score += 5;
		}
		if ( $transition_pct >= 30 ) {
			$score += 15;
		} elseif ( $transition_pct >= 20 ) {
			$score += 5;
		}
		return array(
			'score'          => min( 100, max( 0, $score ) ),
			'flesch'         => round( $flesch, 1 ),
			'avg_sentence'   => round( $avg_sentence, 1 ),
			'passive_pct'    => round( $passive_pct, 1 ),
			'transition_pct' => round( $transition_pct, 1 ),
		);
	}
}
