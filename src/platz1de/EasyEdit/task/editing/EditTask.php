<?php

namespace platz1de\EasyEdit\task\editing;

use Closure;
use platz1de\EasyEdit\selection\BlockListSelection;
use platz1de\EasyEdit\task\ExecutableTask;
use platz1de\EasyEdit\thread\ChunkCollector;
use platz1de\EasyEdit\thread\EditThread;
use platz1de\EasyEdit\thread\modules\StorageModule;
use platz1de\EasyEdit\thread\output\ResultingChunkData;
use platz1de\EasyEdit\thread\output\session\HistoryCacheData;
use platz1de\EasyEdit\thread\ThreadData;
use platz1de\EasyEdit\utils\ExtendedBinaryStream;
use platz1de\EasyEdit\utils\MixedUtils;
use platz1de\EasyEdit\world\ChunkInformation;
use platz1de\EasyEdit\world\HeightMapCache;

abstract class EditTask extends ExecutableTask
{
	protected string $world;

	/**
	 * @param string $world
	 */
	public function __construct(string $world)
	{
		EditThread::getInstance()->setStatus(EditThread::STATUS_PREPARING);
		parent::__construct();
		$this->world = $world;
	}

	/**
	 * @param int[] $chunks
	 */
	public function requestChunks(array $chunks, bool $fastSet): bool
	{
		ChunkCollector::request($chunks);
		while (ThreadData::canExecute() && EditThread::getInstance()->allowsExecution()) {
			if ($this->checkData($fastSet)) {
				return true;
			}
			EditThread::getInstance()->waitForData();
		}
		$this->finalize();
		return false;
	}

	public function checkData(bool $fastSet): bool
	{
		if (ChunkCollector::hasReceivedInput()) {
			$this->run($fastSet);
			ChunkCollector::clean($this->getCacheClosure());
			return true;
		}
		return false;
	}

	/**
	 * @param EditTaskHandler $handler
	 * @param int             $chunk
	 * @return bool
	 * Requests chunks while staying in the same execution
	 * Warning: Tasks calling this need to terminate when returning false immediately
	 * This method doesn't do memory management itself, instead this is the responsibility of the caller
	 */
	public function requestRuntimeChunk(EditTaskHandler $handler, int $chunk): bool
	{
		ChunkCollector::clean(static function (array $chunks): array {
			return $chunks; //cache all
		});
		ChunkCollector::request([$chunk]);
		while (ThreadData::canExecute() && EditThread::getInstance()->allowsExecution()) {
			if (ChunkCollector::hasReceivedInput()) {
				$c = $handler->getOrigin()->getManager()->getChunk($chunk);
				$handler->getResult()->setChunk($chunk, clone $c);
				return true;
			}
			EditThread::getInstance()->waitForData();
		}
		$this->finalize();
		return false;
	}

	/**
	 * @param EditTaskHandler $handler
	 * @param int             $chunk
	 */
	public function sendRuntimeChunk(EditTaskHandler $handler, int $chunk): void
	{
		$this->sendOutputPacket(new ResultingChunkData($this->world, [$chunk => $handler->getResult()->getChunk($chunk)], $handler->prepareInjectionData($chunk)));

		ChunkCollector::getChunks()->filterChunks(function (array $c) use ($chunk): array {
			unset($c[$chunk]);
			return $c;
		});
		$handler->getResult()->filterChunks(function (array $c) use ($chunk): array {
			unset($c[$chunk]);
			return $c;
		});
	}

	public function run(bool $fastSet): void
	{
		$start = microtime(true);

		$handler = new EditTaskHandler(ChunkCollector::getChunks(), $this->getUndoBlockList(), $fastSet);

		EditThread::getInstance()->debug("Task " . $this->getTaskName() . ":" . $this->getTaskId() . " loaded " . $handler->getChunkCount() . " Chunks; Using fast-set: " . ($fastSet ? "true" : "false"));

		HeightMapCache::prepare();

		$this->executeEdit($handler);
		EditThread::getInstance()->debug("Task " . $this->getTaskName() . ":" . $this->getTaskId() . " was executed successful in " . (microtime(true) - $start) . "s, changing " . $handler->getChangedBlockCount() . " blocks (" . $handler->getReadBlockCount() . " read, " . $handler->getWrittenBlockCount() . " written)");

		StorageModule::collect($handler->getChanges());

		EditTaskResultCache::from(microtime(true) - $start, $handler->getChangedBlockCount());

		$this->sendOutputPacket(new ResultingChunkData($this->world, $this->filterChunks($handler->getResult()->getChunks()), $handler->prepareAllInjectionData()));
	}

	public function finalize(): void
	{
		if (!$this->useDefaultHandler()) {
			return;
		}
		$changeId = StorageModule::finishCollecting();
		$this->sendOutputPacket(new HistoryCacheData($changeId, false));
		$this->notifyUser((string) round(EditTaskResultCache::getTime(), 2), MixedUtils::humanReadable(EditTaskResultCache::getChanged()));
		ChunkCollector::clear();
	}

	/**
	 * @param EditTaskHandler $handler
	 */
	abstract public function executeEdit(EditTaskHandler $handler): void;

	/**
	 * @param string $time
	 * @param string $changed
	 */
	abstract public function notifyUser(string $time, string $changed): void;

	/**
	 * Filters actually edited chunks
	 * @param ChunkInformation[] $chunks
	 * @return ChunkInformation[]
	 */
	public function filterChunks(array $chunks): array
	{
		foreach ($chunks as $hash => $chunk) {
			if (!$chunk->wasUsed()) {
				unset($chunks[$hash]);
			}
		}
		return $chunks;
	}

	/**
	 * Filters chunks to stay cached
	 * @return Closure
	 */
	public function getCacheClosure(): Closure
	{
		return static function (array $chunks): array {
			return []; //no cache
		};
	}

	/**
	 * @return BlockListSelection
	 */
	abstract public function getUndoBlockList(): BlockListSelection;

	/**
	 * @return string
	 */
	public function getWorld(): string
	{
		return $this->world;
	}

	public function putData(ExtendedBinaryStream $stream): void
	{
		$stream->putString($this->world);
	}

	public function parseData(ExtendedBinaryStream $stream): void
	{
		$this->world = $stream->getString();
	}
}