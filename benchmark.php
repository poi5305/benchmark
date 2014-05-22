<?php
echo __FILE__."\n";


class BenchMark
{
	var $base_cpu = 6;
	var $parameter = array();
	var $cmds = array();
	var $cmds_paras = array();
	var $log_file = "benchmark_logs";
	var $sge_path = "tmp_sge";
	var $default_sge_cmd = "
#!/bin/sh
#$ -V
#$ -S /bin/bash
";
	
	function BenchMark()
	{
		file_put_contents($this->log_file, "");
	}
	function add_parameter($name, $array)
	{
		$this->parameter[$name] = $array;
		
	}
	function run($cmd)
	{
		$this->make_cmds($cmd);
		foreach($this->cmds_paras as $cmd_para)
		{
			$cmd_idx = $cmd_para["cmd_idx"];
			$start_time =  microtime(true);
			echo $this->cmds[$cmd_idx]."\n";
			shell_exec($this->cmds[$cmd_idx]);
			$end_time =  microtime(true);
			$log = number_format(($end_time - $start_time), 3, '.', '') . "\t" . json_encode($cmd_para) . "\n";
			file_put_contents($this->log_file, $log, FILE_APPEND);
		}
	}
	function sge_run($cmd)
	{
		@mkdir($this->sge_path);
		
		$this->make_cmds($cmd);
		foreach($this->cmds_paras as $cmd_para)
		{
			$cmd_idx = $cmd_para["cmd_idx"];
			$tmp_dir = dirname(__FILE__)."/{$this->sge_path}/" . md5($this->cmds[$cmd_idx]);
			@mkdir($tmp_dir);
			
			$sge_cmd = $this->default_sge_cmd;
			if(isset($cmd_para["cpu"]))
				$sge_cmd .= "#$ -pe single " . ($cmd_para["cpu"]+$this->base_cpu) . "\n";
			else if(isset($cmd_para["cpus"]))
				$sge_cmd .= "#$ -pe single " . ($cmd_para["cpus"]+$this->base_cpu) . "\n";
			else
				$sge_cmd .= "#$ -pe single {$this->base_cpu}\n";
			$sge_cmd .= "#$ -o ".dirname(__FILE__)."/{$this->sge_path}/sge_". md5($this->cmds[$cmd_idx]) .".log  -j y\n";
			$sge_cmd .= "#Parameter\t" . json_encode($cmd_para) . "\n";
			$sge_cmd .= "#cmd\t" . $this->cmds[$cmd_idx]."\n";
			
			$sge_cmd .= "echo '#parameter\t".json_encode($cmd_para)."' \n";
			$sge_cmd .= "echo 'start\t'`date +%T.%3N`\n";
			$sge_cmd .= "cd $tmp_dir\n";
			$sge_cmd .= $this->cmds[$cmd_idx]."\n";
			$sge_cmd .= "rm -r $tmp_dir\n";
			$sge_cmd .= "echo 'end\t'`date +%T.%3N`\n";
			
			file_put_contents($this->sge_path."/sge_".md5($this->cmds[$cmd_idx]).".sge" , $sge_cmd);
			echo $this->cmds[$cmd_idx]."\n";
			echo shell_exec("qsub ".$this->sge_path."/sge_".md5($this->cmds[$cmd_idx]).".sge");
			echo "\n";
		}
	}
	function make_cmds($cmd)
	{
		$total_cmd = 1;
		foreach($this->parameter as $name => $values)
		{
			$total_cmd *= count($values);
		}
		for($i=0;$i<$total_cmd;$i++)
			$this->cmds[] = $cmd;
		
		$para_idx = 1;
		foreach($this->parameter as $name => $values) //3
		{
			$cmds_idx = 0;
			$values_count = count($values);
			for($r_idx=0; $r_idx < $para_idx; $r_idx++) //1 2 3
			{
				foreach($values as $value) //2 2 1
				{		
					for($i=0;$i < floor($total_cmd / $values_count / $para_idx) ;$i++)
					{
						
						$this->cmds[$cmds_idx] = str_replace("\$$name", $value, $this->cmds[$cmds_idx]);
						$this->cmds_paras[$cmds_idx][$name] = $value;
						//$this->cmds_paras[$cmds_idx]["cmds_idx"] = $cmds_idx;
						$cmds_idx++;
					}
				}
			}
			$para_idx *= $values_count;
		}
		foreach($this->cmds_paras as $idx => &$value)
		{
			$value["cmd_idx"] = $idx;
		}
		sort($this->cmds_paras);
	}
};

// example usage

$p_sbwt = "/home/andy/publish/sBWT/bin/sbwt_linux";
$p_genome = "/home/andy/andy/pokemon_0505/sbwt_test3/genome_hg19";
$p_reads = "/home/andy/andy/pokemon_0505/sbwt_test3/reads_hg19";
$p_index = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/index";
$p_log = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/log";
$p_result = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/result";

/*
$bb = new BenchMark();
$bb->base_cpu = 6;
$bb->add_parameter("repeat", array(1) );
$bb->add_parameter("genome", array(1,2,4,8,16) );
$bb->add_parameter("interval", array(64) );
$cmd = "time $p_sbwt build -p $p_index/hg19_\$genomeX_test_400M_\$interval_\$repeat -i $p_genome/hg19_\$genomeX_test_400M.fa -s \$interval -f > ";
$cmd .= "$p_log/log_build_r_\$repeat_g_\$genome_i_\$interval.log 2>&1";
$bb->sge_run($cmd);
exit();
*/

$bm = new BenchMark();
$bm->base_cpu = 6;
$bm->add_parameter("repeat", array(1,2,3,4,5) );
$bm->add_parameter("genome", array(1,2,4,8,16) );
$bm->add_parameter("len", array(40,60,80,100) );
$bm->add_parameter("cpu", array(1) );
$bm->add_parameter("interval", array(64) );

$cmd = "time $p_sbwt map -p $p_index/hg19_\$genomeX_test_400M_\$interval -i $p_reads/s_hg19_400M_reads_\$len.fq -n \$cpu -o ";
$cmd .= "$p_result/result_r_\$repeat_g_\$genome_l_\$len_c_\$cpu_\$interval.sam > ";
$cmd .= "$p_log/log_search_r_\$repeat_g_\$genome_l_\$len_c_\$cpu_\$interval.log 2>&1";
$bm->sge_run($cmd);


?>