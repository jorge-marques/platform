<?php

namespace Oro\Bundle\FormBundle\Tests\Unit\Form\Type;

use Doctrine\Common\Collections\ArrayCollection;

use Symfony\Component\Form\Test\FormIntegrationTestCase;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

use Oro\Bundle\EntityBundle\ORM\DoctrineHelper;
use Oro\Bundle\FormBundle\Form\Type\EntityChangesetType;

class EntityChangesetTypeTest extends FormIntegrationTestCase
{
    /**
     * @var DoctrineHelper|\PHPUnit_Framework_MockObject_MockObject
     */
    protected $doctrineHelper;

    /**
     * @var EntityChangesetType
     */
    protected $type;

    protected function setUp()
    {
        $this->doctrineHelper = $this->getMockBuilder('Oro\Bundle\EntityBundle\ORM\DoctrineHelper')
            ->disableOriginalConstructor()
            ->getMock();

        $this->type = new EntityChangesetType($this->doctrineHelper);

        parent::setUp();
    }

    public function testGetName()
    {
        $this->assertEquals(EntityChangesetType::NAME, $this->type->getName());
    }

    public function testGetParent()
    {
        $this->assertEquals('hidden', $this->type->getParent());
    }

    public function testSetDefaultOptions()
    {
        /** @var OptionsResolverInterface|\PHPUnit_Framework_MockObject_MockObject $resolver */
        $resolver = $this->getMock('Symfony\Component\OptionsResolver\OptionsResolverInterface');
        $resolver->expects($this->once())
            ->method('setRequired')
            ->with(['class']);
        $resolver->expects($this->once())
            ->method('setDefaults')
            ->with(['data_class' => null, 'mapped' => false]);
        $this->type->setDefaultOptions($resolver);
    }

    /**
     * @dataProvider submitProvider
     *
     * @param $defaultData
     * @param $viewData
     * @param $submittedData
     * @param ArrayCollection $expected
     */
    public function testSubmit($defaultData, $viewData, $submittedData, ArrayCollection $expected)
    {
        $this->doctrineHelper->expects($expected->isEmpty() ? $this->never() : $this->exactly($expected->count()))
            ->method('getEntityReference')
            ->will(
                $this->returnCallback(
                    function () {
                        return $this->createDataObject(func_get_arg(1));
                    }
                )
            );

        $form = $this->factory->create($this->type, $defaultData, ['class' => '\stdClass']);

        $this->assertEquals($viewData, $form->getViewData());

        $form->submit($submittedData);
        $this->assertTrue($form->isValid());

        $data = $form->getData();

        $this->assertEquals($expected, $data);
    }

    /**
     * @return array
     */
    public function submitProvider()
    {
        return [
            [
                'defaultData' => null,
                'viewData' => null,
                'submittedData' => json_encode(null),
                'expected'  => new ArrayCollection()
            ],
            [
                'defaultData' => null,
                'viewData' => null,
                'submittedData' => json_encode([]),
                'expected'  => new ArrayCollection()
            ],
            [
                'defaultData' => new ArrayCollection([
                    '1' => ['entity' => $this->createDataObject(1), 'data' => ['test' => '123', 'test2' => 'val']],
                    '2' => ['entity' => $this->createDataObject(2), 'data' => ['test' => '12']]
                ]),
                'viewData' =>  json_encode([
                    '1' => ['test' => '123', 'test2' => 'val'],
                    '2' => ['test' => '12']
                ]),
                'submittedData'  => json_encode([
                    '1' => ['test' => '321', 'test2' => 'val'],
                    '2' => ['test' => '21']
                ]),
                'expected' => new ArrayCollection([
                    '1' => ['entity' => $this->createDataObject(1), 'data' => ['test' => '321', 'test2' => 'val']],
                    '2' => ['entity' => $this->createDataObject(2), 'data' => ['test' => '21']]
                ]),
            ]
        ];
    }

    /**
     * @expectedException \Symfony\Component\Form\Exception\UnexpectedTypeException
     * @expectedExceptionMessage Expected argument of type "array", "string" given
     */
    public function testReverseTransformException()
    {
        $form = $this->factory->create($this->type, null, ['class' => '\stdClass']);
        $form->submit(json_encode('test'));
    }

    /**
     * @param int $id
     * @return \stdClass
     */
    protected function createDataObject($id)
    {
        $obj = new \stdClass();
        $obj->id = $id;

        return $obj;
    }
}
