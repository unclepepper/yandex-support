<?php

declare(strict_types=1);

namespace BaksDev\Yandex\Support\Messenger\NewMessage\YandexSupportNewMessage;

use BaksDev\Support\Entity\Support;
use BaksDev\Support\Repository\FindExistMessage\FindExistExternalMessageByIdInterface;
use BaksDev\Support\Repository\SupportCurrentEventByTicket\CurrentSupportEventByTicketInterface;
use BaksDev\Support\Type\Priority\SupportPriority;
use BaksDev\Support\Type\Priority\SupportPriority\Collection\SupportPriorityLow;
use BaksDev\Support\Type\Status\SupportStatus;
use BaksDev\Support\Type\Status\SupportStatus\Collection\SupportStatusOpen;
use BaksDev\Support\UseCase\Admin\New\Invariable\SupportInvariableDTO;
use BaksDev\Support\UseCase\Admin\New\Message\SupportMessageDTO;
use BaksDev\Support\UseCase\Admin\New\SupportDTO;
use BaksDev\Support\UseCase\Admin\New\SupportHandler;
use BaksDev\Users\Profile\TypeProfile\Type\Id\TypeProfileUid;
use BaksDev\Yandex\Support\Api\Messenger\Get\ChatsInfo\YandexChatsDTO;
use BaksDev\Yandex\Support\Api\Messenger\Get\ChatsInfo\YandexGetChatsInfoRequest;
use BaksDev\Yandex\Support\Api\Messenger\Get\ListMessages\YandexGetListMessagesRequest;
use BaksDev\Yandex\Support\Types\ProfileType\TypeProfileYandexMessageSupport;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler(priority: 0)]
final class NewYandexSupportHandler
{
    private LoggerInterface $logger;

    public function __construct(
        private SupportHandler $supportHandler,
        private CurrentSupportEventByTicketInterface $currentSupportEventByTicket,
        private FindExistExternalMessageByIdInterface $findExistMessage,
        private YandexGetChatsInfoRequest $getChatsInfoRequest,
        private YandexGetListMessagesRequest $messagesRequest,
        LoggerInterface $yandexSupportLogger,
    )
    {
        $this->logger = $yandexSupportLogger;
    }


    public function __invoke(NewYandexSupportMessage $message): void
    {
        /** Получаем все непрочитанные чаты */
        $chats = $this->getChatsInfoRequest
            ->profile($message->getProfile())
            ->findAll();

        if(!$chats->valid())
        {
            return;
        }

        /** @var YandexChatsDTO $chat */
        foreach($chats as $chat)
        {

            /** Получаем ID чата */
            $ticketId = $chat->getId();

            /** Если такой тикет уже существует в БД, то присваиваем в переменную  $supportEvent */
            $supportEvent = $this->currentSupportEventByTicket
                ->forTicket($ticketId)
                ->find();

            /** Получаем сообщения чата  */
            $listMessages = $this->messagesRequest
                ->profile($message->getProfile())
                ->chat($ticketId)
                ->findAll();

            if(!$listMessages->valid())
            {
                continue;
            }

            $SupportDTO = new SupportDTO();

            if($supportEvent)
            {
                $supportEvent->getDto($SupportDTO);
            }

            /** Присваиваем значения по умолчанию */
            if(false === $supportEvent)
            {
                /** Присваиваем приоритет сообщения "высокий", так как это сообщение от пользователя */
                $SupportDTO->setPriority(new SupportPriority(SupportPriorityLow::PARAM));

                /** SupportInvariableDTO */
                $SupportInvariableDTO = new SupportInvariableDTO();

                $SupportInvariableDTO
                    ->setProfile($message->getProfile()) // Профиль
                    ->setType(new TypeProfileUid(TypeProfileYandexMessageSupport::TYPE)) // TypeProfileYandexMessageSupport::TYPE
                    ->setTicket($ticketId)                                       //  Id тикета
                    ->setTitle(sprintf('Заказ #%s', $chat->getOrder()));  // Тема сообщения

                /** Сохраняем данные SupportInvariableDTO в Support */
                $SupportDTO->setInvariable($SupportInvariableDTO);
            }

            /** Присваиваем статус "Открытый", так как сообщение еще не прочитано   */
            $SupportDTO->setStatus(new SupportStatus(SupportStatusOpen::PARAM));

            $isHandle = false;

            foreach($listMessages as $listMessage)
            {
                /** Пропускаем, если сообщение системное  */
                if($listMessage->getSender() === 'MARKET')
                {
                    continue;
                }

                /** Если такое сообщение уже есть в БД, то пропускаем */
                $messageExist = $this->findExistMessage
                    ->external($listMessage->getExternalId())
                    ->exist();

                if($messageExist)
                {
                    continue;
                }

                /** Имя отправителя сообщения */
                $name = $listMessage->getSender();

                /** Текст сообщения */
                $text = $listMessage->getText();

                /** SupportMessageDTO */
                $SupportMessageDTO = new SupportMessageDTO();

                $SupportMessageDTO
                    ->setExternal($listMessage->getExternalId())    // Внешний (Авито) id сообщения
                    ->setName($name)                                // Имя отправителя сообщения
                    ->setMessage($text)                             // Текст сообщения
                    ->setDate($listMessage->getCreated())           // Дата сообщения
                ;

                /** Если это сообщение изначально наше, то сохраняем как 'out' */
                $listMessage->getSender() === 'PARTNER' ?
                    $SupportMessageDTO->setOutMessage() :
                    $SupportMessageDTO->setInMessage();


                /** Сохраняем данные SupportMessageDTO в Support */
                $isAddMessage = $SupportDTO->addMessage($SupportMessageDTO);

                if(false === $isHandle && true === $isAddMessage)
                {
                    $isHandle = true;
                }
            }

            if($isHandle)
            {
                /** Сохраняем в БД */
                $handle = $this->supportHandler->handle($SupportDTO);

                if(false === ($handle instanceof Support))
                {
                    $this->logger->critical(
                        sprintf('yandex-support: Ошибка %s при обновлении чата', $handle),
                        [self::class.':'.__LINE__]
                    );
                }
            }
        }
    }
}
