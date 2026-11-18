<?php

namespace Mothership\Product\Validator;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Mothership\Attribute\Services\AttributeService;
use Mothership\Core\Repositories\ChannelRepository;
use Mothership\Product\Type\AbstractType;
use Mothership\Product\Validator\Rule\AttributeValueRule;
use Mothership\Product\Validator\Rule\KeyExistsRule;

class ChannelValuesValidator
{
    /**
     * create channelValuesValidator object
     */
    public function __construct(
        protected ChannelRepository $channelRepository,
        protected AttributeService $attributeService
    ) {}

    /**
     * Validates the channel based product values data according to attribute value rules
     *
     * @throws ValidationException
     */
    public function validate(mixed $data, array $options = [], ?array $channels = null, ?string $id = null): void
    {
        $channels ??= $this->channelRepository->all()->pluck('code')->toArray();

        $rules = $this->generateRules($channels, $data, $id);

        $validator = Validator::make($data, $rules);

        throw_if($validator->fails(), ValidationException::class, $validator);
    }

    /**
     * Validation rules to be used on the data
     */
    protected function generateRules(array $channels = [], array $data = [], ?string $productId = null): array
    {
        return [
            AbstractType::CHANNEL_VALUES_KEY.'.*'   => [new KeyExistsRule($channels, AbstractType::CHANNEL_VALUES_KEY.'.'), 'array'],
            AbstractType::CHANNEL_VALUES_KEY.'.*.*' => new AttributeValueRule(attributeService: $this->attributeService, isChannelBased: true, isLocaleBased: false, productId: $productId),
        ];
    }
}
