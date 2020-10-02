<?php


namespace app\components\parser\news;

use app\components\Helper;
use app\components\parser\NewsPost;
use app\components\parser\NewsPostItem;
use app\components\parser\ParserInterface;
use yii\base\ErrorException;

class NewInform implements ParserInterface
{
    const USER_ID = 2;
    const FEED_ID = 2;

    /**
     * Ссылка на API. И пути к ним.
     */
    const URL = "https://newinform.com/posts/get";
    const PATH_LIMIT = "?limit=";

    const TEXT_ERROR = 'Ошибка с получением новостей';
    /**
     * Количество постов на запрос.
     */
    const COUNT_ITEMS_IN_PAGE = 10;

    /**
     * Данные для вычета из даты.
     */
    const TIME_UTC0 = "-3 hours";

    /**
     * Задаем переменную для записи в нее постов.
     * @var array
     */
    private static array $posts = [];

    /**
     * Главный метод формирование массива с постами.
     * @return array
     * @throws \Exception
     */
    public static function run(): array
    {
        // Отправялем запрос на сервер
        $curlGet = Helper::getCurl()
            ->setOption(CURLOPT_SSL_VERIFYPEER, false);

        // Получаем нужные нам данные
        $getContains = json_decode($curlGet->get(
                                         self::URL .
                                             self::PATH_LIMIT.
                                             self::COUNT_ITEMS_IN_PAGE
        ));

        if (!$getContains) {
            throw new ErrorException(self::TEXT_ERROR);
        }

        self::foreachPosts($getContains);

        return self::$posts;

    }

    /**
     * Проходим по каждому посту.
     * @param object $items
     *
     * @throws \Exception
     */
    private static function foreachPosts(object $items) : void
    {
        foreach ($items->posts->data as $item) {
            $timestampItem = strtotime($item->public_publish_date);
            $newTimestamp = strtotime(self::TIME_UTC0, $timestampItem);

            $post = new NewsPost(static::class,
                                 $item->title,
                                 $item->seo_description,
                                 date('Y-m-d H:i:s', $newTimestamp),
                                 $item->fulllink,
                                 $item->mainImageLink->original);

            // Блок добавления текста к посту.
            self::typeItem($post,
                           NewsPostItem::TYPE_TEXT,
                           $item->content);

            // Блок добавления хеддера к посту.
            self::typeItem($post,
                           NewsPostItem::TYPE_HEADER,
                           $item->title,
                           null,
                           null,
                           6);

            self::$posts[] = $post;
        }

    }

    /**
     * Метод записи айтемов к посту.
     *
     * @param object      $post
     * @param string|null $type
     * @param string|null $description
     * @param string|null $image
     * @param string|null $link
     * @param string|null $header
     * @param string|null $youtube
     */
    private static function typeItem(object $post,
                                     ?string $type = null,
                                     ?string $description = null,
                                     ?string $image = null,
                                     ?string $link = null,
                                     ?string $header = null,
                                     ?string $youtube = null
    ) : void {
        $post->addItem(
            new NewsPostItem(
                $type,
                $description,
                $image,
                $link,
                $header,
                $youtube
            )
        );
    }
}
