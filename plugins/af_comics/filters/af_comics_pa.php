<?php
class Af_Comics_Pa extends Af_ComicFilter {

	function supported() {
		return array("Penny Arcade");
	}

	function process(&$article) {
		if (str_contains($article["link"], "penny-arcade.com") && str_contains($article["title"], "Comic:")) {

				$doc = new DOMDocument();

				if ($doc->loadHTML(UrlHelper::fetch(['url' => $article['link']]))) {
					$xpath = new DOMXPath($doc);
					$basenode = $xpath->query('(//div[@id="comicFrame"])')->item(0);

					if ($basenode) {
						$article["content"] = $doc->saveHTML($basenode);
					}
				}

			return true;
		}

		if (str_contains($article["link"], "penny-arcade.com") && str_contains($article["title"], "News Post:")) {
				$doc = new DOMDocument();

				$res = UrlHelper::fetch(['url' => $article['link']]);

				if ($res && $doc->loadHTML($res)) {
					$xpath = new DOMXPath($doc);
					$entries = $xpath->query('(//div[@class="post"])');

					$basenode = false;

					foreach ($entries as $entry) {
						$basenode = $entry;
					}

					$meta = $xpath->query('(//div[@class="meta"])')->item(0);
					if ($meta->parentNode) { $meta->parentNode->removeChild($meta); }

					$header = $xpath->query('(//div[@class="postBody"]/h2)')->item(0);
					if ($header->parentNode) { $header->parentNode->removeChild($header); }

					$header = $xpath->query('(//div[@class="postBody"]/div[@class="comicPost"])')->item(0);
					if ($header->parentNode) { $header->parentNode->removeChild($header); }

					$avatar = $xpath->query('(//div[@class="avatar"]//img)')->item(0);

					if ($basenode)
						$basenode->insertBefore($avatar, $basenode->firstChild);

					$uninteresting = $xpath->query('(//div[@class="avatar"])');
					foreach ($uninteresting as $i) {
						$i->parentNode->removeChild($i);
					}

					if ($basenode){
						$article["content"] = $doc->saveHTML($basenode);
					}
				}

			return true;
		}

		return false;
	}
}
