<?php

namespace Divisions\Monolog\Handler\Tracy;

use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Handler\HandlerInterface;
use Nette\Database\Helpers;
use Tracy;

/**
 * Class PropelPanel
 * @package Divisions\Monolog\Handler\Tracy
 */
class PropelPanel
	extends AbstractProcessingHandler
	implements Tracy\IBarPanel, HandlerInterface{

	/**
	 * @var string Propel channel name
	 */
	private $channelName;

	/** @var int logged time */
	private $totalTime = 0;

	/** @var array */
	private $queries = [];

	/** @var int */
	private $profiledQueriesCounter = 0;

	/** @var int */
	private $unprofiledQueriesCounter = 0;

	/**
	 * @param array $record
	 */
	public function logQuery(array $record){

		if(preg_match('/Time:(?:\s+)(?P<time>\d+|\d*\.\d+)ms \| Memory:(?:\s+)(?:\d+|\d*\.\d+)(?:[a-z]{2}) \| (?P<query>.*)/i',
		              $record['message'],
		              $matches)){
			$this->profiledQueriesCounter += 1;
			$this->queries[] = $record + $matches;
			$currentQuery    = $matches;
		}else{
			$this->unprofiledQueriesCounter += 1;
			$this->queries[] = $record + $matches = ['time' => null, 'query' => $record['message']];
			$currentQuery    = $matches;
		}

		$this->totalTime += floatval($currentQuery['time']);
	}

	/**
	 * @return string
	 */
	public function getTab(){
		return '<span title="Propel | Channel: '.htmlSpecialChars($this->channelName, ENT_QUOTES, 'UTF-8').'">
		<svg viewBox="0 0 2048 2048"><path fill="'.($this->queries ? '#469ED6' : '#A0B2BE').'" d="M1024 896q237 0 443-43t325-127v170q0 69-103 128t-280 93.5-385 34.5-385-34.5-280-93.5-103-128v-170q119 84 325 127t443 43zm0 768q237 0 443-43t325-127v170q0 69-103 128t-280 93.5-385 34.5-385-34.5-280-93.5-103-128v-170q119 84 325 127t443 43zm0-384q237 0 443-43t325-127v170q0 69-103 128t-280 93.5-385 34.5-385-34.5-280-93.5-103-128v-170q119 84 325 127t443 43zm0-1152q208 0 385 34.5t280 93.5 103 128v128q0 69-103 128t-280 93.5-385 34.5-385-34.5-280-93.5-103-128v-128q0-69 103-128t280-93.5 385-34.5z"/><span class="tracy-label">'.($this->totalTime ? sprintf('%0.1f ms | ',
		                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                 $this->totalTime) : '').$this->profiledQueriesCounter.' / '.$this->unprofiledQueriesCounter.'</span></span>';
	}

	/**
	 * @return string
	 */
	public function getPanel(){
		$output = '';

		foreach($this->queries AS $record){
			$time  = $record['time'];
			$query = $record['query'];
			$output .= '<tr><td>';
			$output .= ($time ? $time.'&nbsp;ms' : 'null');

			$output .= '</td><td>'.Helpers::dumpSql($query);

			$output .= '</td></tr>';
		}

		return empty($this->queries) ? '' : '<style> #tracy-debug .tracy-PropelPanel tr table { margin: 8px 0; max-height: 150px; overflow:auto }</style>
			<h1>Channel: '.htmlSpecialChars($this->channelName,
		                                    ENT_QUOTES,
		                                    'UTF-8').' | Queries: '.htmlSpecialChars($this->profiledQueriesCounter,
		                                                                             ENT_QUOTES,
		                                                                             'UTF-8').' / '.htmlSpecialChars($this->unprofiledQueriesCounter,
		                                                                                                             ENT_QUOTES,
		                                                                                                             'UTF-8').' | Time: '.htmlSpecialChars($this->totalTime,
		                                                                                                                                                   ENT_QUOTES,
		                                                                                                                                                   'UTF-8').'ms</h1>
			<div class="tracy-inner tracy-PropelPanel" style="max-width: 800px;">
			<table>
				<tr><th>Time</th><th>SQL Statement</th></tr>'.$output.'
			</table>
			</div>';
	}

	/**
	 * @param array $record
	 */
	public function write(array $record){
		$this->logQuery($record);
	}

	/**
	 * @param $name
	 */
	public function setChannelName($name){
		$this->channelName = $name;
	}

	/**
	 * @return NormalizerFormatter
	 */
	protected function getDefaultFormatter(){
		return new NormalizerFormatter();
	}

}