<?php
abstract class FeedItem_Common extends FeedItem {
	function __construct(
		protected readonly DOMElement $elem,
		protected readonly DOMDocument $doc,
		protected readonly DOMXPath $xpath,
	) {
		try {
			$source = $elem->getElementsByTagName("source")->item(0);

			// we don't need <source> element
			if ($source)
				$elem->removeChild($source);
		} catch (DOMException $e) {
			//
		}
	}

	function get_element(): DOMElement {
		return $this->elem;
	}

	function get_author(): string {
		/** @var DOMElement|null */
		$author = $this->elem->getElementsByTagName("author")->item(0);

		if ($author) {
			$name = $author->getElementsByTagName("name")->item(0);

			if ($name) return clean($name->nodeValue);

			$email = $author->getElementsByTagName("email")->item(0);

			if ($email) return clean($email->nodeValue);

			if ($author->nodeValue)
				return clean($author->nodeValue);
		}

		$author_elems = $this->xpath->query("dc:creator", $this->elem);
		$authors = [];

		foreach ($author_elems as $author) {
			array_push($authors, clean($author->nodeValue));
		}

		return implode(", ", $authors);
	}

	function get_comments_url(): string {
		//RSS only. Use a query here to avoid namespace clashes (e.g. with slash).
		//might give a wrong result if a default namespace was declared (possible with XPath 2.0)
		$com_url = $this->xpath->query("comments", $this->elem)->item(0);

		if ($com_url)
			return clean($com_url->nodeValue);

		//Atom Threading Extension (RFC 4685) stuff. Could be used in RSS feeds, so it's in common.
		//'text/html' for type is too restrictive?
		$com_url = $this->xpath->query("atom:link[@rel='replies' and contains(@type,'text/html')]/@href", $this->elem)->item(0);

		if ($com_url)
			return clean($com_url->nodeValue);

		return '';
	}

	function get_comments_count(): int {
		//also query for ATE stuff here
		$query = "slash:comments|thread:total|atom:link[@rel='replies']/@thread:count";
		$comments = $this->xpath->query($query, $this->elem)->item(0);

		if ($comments && is_numeric($comments->nodeValue)) {
			return (int) clean($comments->nodeValue);
		}

		return 0;
	}

	/**
	 * this is common for both Atom and RSS types and deals with various 'media:' elements
	 *
	 * @return array<int, FeedEnclosure>
	 */
	function get_enclosures(): array {
		$encs = [];

		$enclosures = $this->xpath->query("media:content", $this->elem);

		foreach ($enclosures as $enclosure) {
			$enc = new FeedEnclosure(
				type: clean($enclosure->getAttribute('type')),
				link: clean($enclosure->getAttribute('url')),
				length: clean($enclosure->getAttribute('length')),
				height: clean($enclosure->getAttribute('height')),
				width: clean($enclosure->getAttribute('width')),
			);

			$medium = clean($enclosure->getAttribute("medium"));
			if (!$enc->type && $medium) {
				$enc->type = strtolower("$medium/generic");
			}

			$desc = $this->xpath->query("media:description", $enclosure)->item(0);
			if ($desc) $enc->title = clean($desc->nodeValue);

			array_push($encs, $enc);
		}

		$enclosures = $this->xpath->query("media:group", $this->elem);

		foreach ($enclosures as $enclosure) {
			/** @var DOMElement|null */
			$content = $this->xpath->query("media:content", $enclosure)->item(0);

			if ($content) {
				$enc = new FeedEnclosure(
					type: clean($content->getAttribute('type')),
					link: clean($content->getAttribute('url')),
					length: clean($content->getAttribute('length')),
					height: clean($content->getAttribute('height')),
					width: clean($content->getAttribute('width')),
				);

				$medium = clean($content->getAttribute("medium"));
				if (!$enc->type && $medium) {
					$enc->type = strtolower("$medium/generic");
				}

				$desc = $this->xpath->query("media:description", $content)->item(0);
				if ($desc) {
					$enc->title = clean($desc->nodeValue);
				} else {
					$desc = $this->xpath->query("media:description", $enclosure)->item(0);
					if ($desc) $enc->title = clean($desc->nodeValue);
				}

				array_push($encs, $enc);
			}
		}

		$enclosures = $this->xpath->query("media:thumbnail", $this->elem);

		foreach ($enclosures as $enclosure) {
			$encs[] = new FeedEnclosure(
				type: 'image/generic',
				link: clean($enclosure->getAttribute('url')),
				height: clean($enclosure->getAttribute('height')),
				width: clean($enclosure->getAttribute('width')),
			);
		}

		return $encs;
	}

	function count_children(DOMElement $node): int {
		return $node->getElementsByTagName("*")->length;
	}

	/**
	 * @return false|string false on failure, otherwise string contents
	 */
	function subtree_or_text(DOMElement $node): false|string {
		if ($this->count_children($node) == 0) {
			return $node->nodeValue;
		} else {
			return $node->c14n();
		}
	}

	/**
	 * @param array<int, string> $cats
	 *
	 * @return array<int, string>
	 */
	static function normalize_categories(array $cats): array {

		$tmp = [];

		foreach ($cats as $rawcat) {
			array_push($tmp, ...explode(",", $rawcat));
		}

		$tmp = array_map(function($srccat) {
			$cat = clean(trim(mb_strtolower($srccat)));

			// we don't support numeric tags
			if (is_numeric($cat))
				$cat = 't:' . $cat;

			$cat = preg_replace('/[,\'\"]/', "", $cat);

			if (Config::get(Config::DB_TYPE) == "mysql") {
				$cat = preg_replace('/[\x{10000}-\x{10FFFF}]/u', "\xEF\xBF\xBD", $cat);
			}

			if (mb_strlen($cat) > 250)
				$cat = mb_substr($cat, 0, 250);

			return $cat;
		}, $tmp);

		// remove empty values
		$tmp = array_filter($tmp, 'strlen');

		asort($tmp);

		return array_unique($tmp);
	}
}
