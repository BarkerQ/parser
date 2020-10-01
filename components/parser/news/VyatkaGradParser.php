<?php

namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use lanfix\parser\src\Element;
use yii\base\ErrorException;
use lanfix\parser\Parser;

/**
 * Парсер новостей с сайта https://vyatka-grad.ru/
 * @author lanfix
 */
class VyatkaGradParser implements ParserInterface
{

    const USER_ID = 2;
    const FEED_ID = 2;

    const SRC = 'https://vyatka-grad.ru/';

    /**
     * @inheritDoc
     */
    public static function run(): array
    {
        /**
         * Вырубаем нотисы
         */
        error_reporting(E_ALL & ~E_NOTICE);
        /**
         * ### Стадия #1 - Получение ссылок на статьи
         * Получаем содержимое страницы новостей
         */
        $curl = Helper::getCurl();
        $curlResult = $curl->get(static::SRC . 'zapisi');
        $newsPageParser = new Parser($curlResult, true);
        $newsPageBody = $newsPageParser->document->getBody();
        $newsContainer = $newsPageBody->findOne('#main');
        /** Если проблемы с получением, то выбрасываем ошибку */
        if (!$newsContainer) {
            throw new ErrorException('Не удалось получить список новостей');
        }
        /** Ищем новостные плашки на странице */
        $newsCardsOnPage = $newsContainer->find('.post');
        /** Получаем список ссылок на статьи */
        $newsLinks = array_map(function (Element $card) {
            if (!$cardHeaderNode = $card->findOne('.entry-title')) {
                return '';
            }
            if (!$cardLinkNode = $cardHeaderNode->findOne('a')) {
                return '';
            }
            if (!$link = $cardLinkNode->getAttribute('href')) {
                return '';
            }
            return trim($link);
        }, $newsCardsOnPage);
        /** Удаляем пустые ссылки */
        $newsLinksFiltered = array_filter($newsLinks, function ($link) {
            return (bool)$link;
        });
        /**
         * ### Стадия #2 - Получение данных со страниц статей
         * Получаем страницы новостей по отдельности
         */
        foreach ($newsLinksFiltered as $newLink) {
            /**
             * Создание парсера и получение данных со страницы
             * конкретной новости
             */
            $curl = Helper::getCurl();
            $curlResult = $curl->get($newLink);
            $newPageParser = new Parser($curlResult, true);
            $newPageBody = $newPageParser->document->getBody();
            $newPageHead = $newPageParser->document->getHead();
            /**
             * Пропускаем статью если нема контента
             */
            if (!$newContain = $newPageBody->findOne('article')) {
                continue;
            }
            /**
             * Дергаем заголовок
             */
            $titleHtmlNode = $newContain->findOne('.entry-title');
            if (!$header = ($titleHtmlNode ? $titleHtmlNode->asText() : '')) {
                continue;
            }
            /**
             * Получаем описание
             */
            $description = '';
            foreach ($newPageHead->find('meta') ?? [] as $meta) {
                if ($meta->getAttribute('name') === 'description') {
                    $description = $meta->getAttribute('content') ?: '';
                    break;
                }
            }
            /**
             * Получаем URL до главной фотки
             */
            $photoUrl = '';
            if ($photoHtmlNode = $newContain->findOne('.single-thumb img')) {
                $photoUrl = trim($photoHtmlNode->getAttribute('src') ?: '');
            }
            Helper::handleUrl($photoUrl, static::SRC);
            /**
             * Получаем время создания статьи
             */
            $newTime = '';
            if ($newTimeNode = $newContain->findOne('time')) {
                $newTime = $newTimeNode->getAttribute('datetime') ?: '';
                $newTime = date('Y-m-d H:i:s', strtotime($newTime));
            }
            /**
             * Генерируем новостной пост
             */
            $post = new NewsPost(static::class, $header, $description, $newTime, $newLink, $photoUrl);
            /**
             * Получаем текстовое содержимое
             * @var Element $containBlock
             */
            if (!$textOfCurrentNew = $newContain->findOne('.entry-content')) {
                continue;
            }
            foreach ($textOfCurrentNew->getChildren() ?: [] as $containBlock) {
                if ($containBlock->tag === 'p' && $img = $containBlock->findOne('img')) {
                    if ($pathToImage = $img->getAttribute('src') ?: '') {
                        Helper::handleUrl($pathToImage, static::SRC);
                        /** Пропускаем фотографию, если она стоит на обложке */
                        if ($pathToImage === $photoUrl) {
                            continue;
                        }
                        $post->addItem(new NewsPostItem(NewsPostItem::TYPE_IMAGE, null,
                            $pathToImage, null, null, null));
                    }
                } elseif ($containBlock->tag === 'p') {
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_TEXT, $containBlock->asText(),
                        null, null, null, null));
                } elseif (preg_match('/h[1-7]/ui', $containBlock->tag)) {
                    $level = (int)substr($containBlock->tag, 1, 1);
                    $post->addItem(new NewsPostItem(NewsPostItem::TYPE_HEADER, $containBlock->asText(),
                        null, null, ($level > 0 ? $level : null), null));
                }
            }
            /** Докидываем пост в список */
            $posts[] = $post;
        }

        print_r($posts ?? []);
        die;


        return $posts ?? [];
    }

}