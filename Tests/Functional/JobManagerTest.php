<?php

namespace JMS\JobQueueBundle\Tests\Functional;

use JMS\JobQueueBundle\Retry\ExponentialRetryScheduler;
use JMS\JobQueueBundle\Tests\Functional\TestBundle\Entity\Train;
use JMS\JobQueueBundle\Tests\Functional\TestBundle\Entity\Wagon;
use PHPUnit\Framework\Constraint\LogicalNot;
use Doctrine\ORM\EntityManager;
use JMS\JobQueueBundle\Entity\Repository\JobManager;
use JMS\JobQueueBundle\Event\StateChangeEvent;
use JMS\JobQueueBundle\Entity\Job;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class JobManagerTest extends BaseTestCase
{
    /** @var EntityManager */
    private $em;

    /** @var JobManager */
    private $jobManager;

    private $dispatcher;

    public function testFindPendingJobReturnsAllDependencies()
    {
        $a = new Job('a');
        $b = new Job('b');

        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $c = new Job('c');
        $c->addDependency($a);
        $c->addDependency($b);
        $this->em->persist($c);
        $this->em->flush();
        $this->em->clear();

        $cReloaded = $this->jobManager->findPendingJob(array($a->getId(), $b->getId()));
        $this->assertNotNull($cReloaded);
        $this->assertEquals($c->getId(), $cReloaded->getId());
        $this->assertCount(2, $cReloaded->getDependencies());
    }

    public function testFindPendingJob()
    {
        $this->assertNull($this->jobManager->findPendingJob());

        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->assertSame($b, $this->jobManager->findPendingJob());
        $this->assertNull($this->jobManager->findPendingJob(array($b->getId())));
    }

    public function testFindPendingJobInRestrictedQueue()
    {
        $this->assertNull($this->jobManager->findPendingJob());

        $a = new Job('a');
        $b = new Job('b', array(), true, 'other_queue');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->assertSame($a, $this->jobManager->findPendingJob());
        $this->assertSame($b, $this->jobManager->findPendingJob(array(), array(), array('other_queue')));
    }

    public function testFindStartableJob()
    {
        $this->assertNull($this->jobManager->findStartableJob('my-name'));

        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $c = new Job('c');
        $b->addDependency($c);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($c);
        $this->em->flush();

        $excludedIds = array();

        $this->assertSame($c, $this->jobManager->findStartableJob('my-name', $excludedIds));
        $this->assertEquals(array($b->getId()), $excludedIds);
    }

    public function testFindJobByRelatedEntity()
    {
        $a = new Job('a');
        $b = new Job('b');
        $b->addRelatedEntity($a);
        $b2 = new Job('b');
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->persist($b2);
        $this->em->flush();
        $this->em->clear();

        $this->assertFalse($this->em->contains($b));

        $reloadedB = $this->jobManager->findJobForRelatedEntity('b', $a);
        $this->assertNotNull($reloadedB);
        $this->assertEquals($b->getId(), $reloadedB->getId());
        $this->assertCount(1, $reloadedB->getRelatedEntities());
        $this->assertEquals($a->getId(), $reloadedB->getRelatedEntities()->first()->getId());
    }

    public function testFindStartableJobDetachesNonStartableJobs()
    {
        $a = new Job('a');
        $b = new Job('b');
        $a->addDependency($b);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        $this->assertTrue($this->em->contains($a));
        $this->assertTrue($this->em->contains($b));

        $excludedIds = array();
        $startableJob = $this->jobManager->findStartableJob('my-name', $excludedIds);
        $this->assertNotNull($startableJob);
        $this->assertEquals($b->getId(), $startableJob->getId());
        $this->assertEquals(array($a->getId()), $excludedIds);
        $this->assertFalse($this->em->contains($a));
        $this->assertTrue($this->em->contains($b));
    }

    public function testCloseJob()
    {
        $a = new Job('a');
        $a->setState('running');
        $b = new Job('b');
        $b->addDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        // ! at() is deprecated

        // $this->dispatcher->expects($this->at(0))
        //     ->method('dispatch')
        //     ->with(new StateChangeEvent($a, 'terminated'));
        // $this->dispatcher->expects($this->at(1))
        //     ->method('dispatch')
        //     ->with(new StateChangeEvent($b, 'canceled'));

        $this->assertEquals('running', $a->getState());
        $this->assertEquals('pending', $b->getState());
        $this->jobManager->closeJob($a, 'terminated');
        $this->assertEquals('terminated', $a->getState());
        $this->assertEquals('canceled', $b->getState());
    }

    public function testCloseJobDoesNotCreateRetryJobsWhenCanceled()
    {
        $a = new Job('a');
        $a->setMaxRetries(4);
        $b = new Job('b');
        $b->setMaxRetries(4);
        $b->addDependency($a);
        $this->em->persist($a);
        $this->em->persist($b);
        $this->em->flush();

        // ! at() is deprecated

        // $this->dispatcher->expects($this->at(0))
        //     ->method('dispatch')
        //     ->with(new StateChangeEvent($a, 'canceled'));

        // $this->dispatcher->expects($this->at(1))
        //     ->method('dispatch')
        //     ->with(new StateChangeEvent($b, 'canceled'));

        $this->jobManager->closeJob($a, 'canceled');
        $this->assertEquals('canceled', $a->getState());
        $this->assertCount(0, $a->getRetryJobs());
        $this->assertEquals('canceled', $b->getState());
        $this->assertCount(0, $b->getRetryJobs());
    }

    public function testCloseJobDoesNotCreateMoreThanAllowedRetries()
    {
        $a = new Job('a');
        $a->setMaxRetries(2);
        $a->setState('running');
        $this->em->persist($a);
        $this->em->flush();

        // ! at() is deprecated
        // $this->dispatcher->expects($this->at(0))
        //     ->method('dispatch')
        //     ->with(new StateChangeEvent($a, 'failed'));
        // $this->dispatcher->expects($this->at(1))
        //     ->method('dispatch')
        //     ->with(new LogicalNot($this->equalTo(new StateChangeEvent($a, 'failed'))));
        // $this->dispatcher->expects($this->at(2))
        //     ->method('dispatch')
        //     ->with(new LogicalNot($this->equalTo(new StateChangeEvent($a, 'failed'))));

        $this->assertCount(0, $a->getRetryJobs());
        $this->jobManager->closeJob($a, 'failed');
        $this->assertEquals('running', $a->getState());
        $this->assertCount(1, $a->getRetryJobs());

        $a->getRetryJobs()->first()->setState('running');
        $this->jobManager->closeJob($a->getRetryJobs()->first(), 'failed');
        $this->assertCount(2, $a->getRetryJobs());
        $this->assertEquals('failed', $a->getRetryJobs()->first()->getState());
        $this->assertEquals('running', $a->getState());

        $a->getRetryJobs()->last()->setState('running');
        $this->jobManager->closeJob($a->getRetryJobs()->last(), 'terminated');
        $this->assertCount(2, $a->getRetryJobs());
        $this->assertEquals('terminated', $a->getRetryJobs()->last()->getState());
        $this->assertEquals('terminated', $a->getState());

        $this->em->clear();
        $reloadedA = $this->em->find('JMSJobQueueBundle:Job', $a->getId());
        $this->assertCount(2, $reloadedA->getRetryJobs());
    }

    public function testModifyingRelatedEntity()
    {
        $wagon = new Wagon();
        $train = new Train();
        $wagon->train = $train;

        $defEm = self::$kernel->getContainer()->get('doctrine')->getManager('default');
        $defEm->persist($wagon);
        $defEm->persist($train);
        $defEm->flush();

        $j = new Job('j');
        $j->addRelatedEntity($wagon);
        $this->em->persist($j);
        $this->em->flush();

        $defEm->clear();
        $this->em->clear();
        $this->assertNotSame($defEm, $this->em);

        $reloadedJ = $this->em->find('JMSJobQueueBundle:Job', $j->getId());

        $reloadedWagon = $reloadedJ->findRelatedEntity('JMS\JobQueueBundle\Tests\Functional\TestBundle\Entity\Wagon');
        $reloadedWagon->state = 'broken';
        $defEm->persist($reloadedWagon);
        $defEm->flush();

        $this->assertTrue($defEm->contains($reloadedWagon->train));
    }

    protected function setUp(): void
    {
        $this->createClient();

        $this->importDatabaseSchema();

        /**
         * @var EventDispatcherInterface $dispatcher
         */
        $this->dispatcher = $this->createMock(EventDispatcherInterface::class);

        $this->em = self::$kernel->getContainer()->get('doctrine')->getManagerForClass(Job::class);

        $this->jobManager = new JobManager(
            self::$kernel->getContainer()->get('doctrine'),
            $this->dispatcher,
            new ExponentialRetryScheduler()
        );
    }
}
