<?php
echo __FILE__."\n";


class BenchMarkCmd
{
	var $parameter = array();
	var $cmds = array();
	var $cmds_paras = array();
	
	function BenchMarkCmd()
	{}
	function add_parameter($name, $array)
	{
		$this->parameter[$name] = $array;
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
						$cmds_idx++;
					}
				}
			}
			$para_idx *= $values_count;
		}
		foreach($this->cmds_paras as $idx => &$value)
		{
			$value["cmd_idx"] = $idx;
			$value["cmd_md5"] = md5($this->cmds[$idx]);
		}
		sort($this->cmds_paras);
	}
};

class BenchMarkRun
{
	//variable
	var $project_name = "";
	var $c_cmds = NULL; //current cmds obj
	
	//config
	var $log_path = "logs";
	var $run_path = "runs";
	
	//sge run
	var $base_cpu = 6;
	var $sge_path = "tmp_sge";
	var $default_sge_cmd = "#!/bin/sh\n#$ -V\n#$ -S /bin/bash\n";

	
	function get_basic_running_script(&$cmd_para)
	{
		$tmp_dir = dirname(__FILE__)."/{$this->run_path}/" . $cmd_para["cmd_md5"];	
		@mkdir($tmp_dir);
		
		$script = "echo '#project\t{$this->project_name}' \n";
		$script .= "echo '#parameter\t".json_encode($cmd_para)."' \n";
		$script .= "echo '#start\t'`date +%T.%3N`\n";
		$script .= "cd $tmp_dir\n";
		$script .= $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
		$script .= "rm -r $tmp_dir\n";
		$script .= "echo '#end\t'`date +%T.%3N`\n";
		
		return $script;
	}
	function get_log_path(&$cmd_para)
	{
		$path = dirname(__FILE__)."/{$this->log_path}/log_". $cmd_para["cmd_md5"] .".log";
		return $path;
	}
	
	function default_run()
	{
		foreach($this->c_cmds->cmds_paras as $cmd_para)
		{
			$cmd_idx = $cmd_para["cmd_idx"];
			$start_time =  microtime(true);
			echo $this->c_cmds->cmds[$cmd_idx]."\n";
			//shell_exec($this->cmds[$cmd_idx]);
			$end_time =  microtime(true);
			$log = number_format(($end_time - $start_time), 3, '.', '') . "\t" . json_encode($cmd_para) . "\n";
			file_put_contents($this->log_file, $log, FILE_APPEND);
		}
	}
	
	function get_sge_script_header(&$cmd_para)
	{
		$sge_cmd = $this->default_sge_cmd;
		if(isset($cmd_para["cpu"]))
			$sge_cmd .= "#$ -pe single " . ($cmd_para["cpu"]+$this->base_cpu) . "\n";
		else if(isset($cmd_para["cpus"]))
			$sge_cmd .= "#$ -pe single " . ($cmd_para["cpus"]+$this->base_cpu) . "\n";
		else
			$sge_cmd .= "#$ -pe single {$this->base_cpu}\n";
		$sge_cmd .= "#$ -o ".$this->get_log_path($cmd_para)."\n";
		$sge_cmd .= "#$ -j y\n";
		$sge_cmd .= "#Parameter\t" . json_encode($cmd_para) . "\n";
		$sge_cmd .= "#cmd\t" . $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
		return $sge_cmd;
	}
	function sge_run()
	{
		@mkdir($this->sge_path);
		
		foreach($this->c_cmds->cmds_paras as $cmd_para)
		{
			$sge_cmd = $this->get_sge_script_header($cmd_para);
			$sge_cmd .= $this->get_basic_running_script($cmd_para);
			
			file_put_contents($this->sge_path."/sge_".$cmd_para["cmd_md5"].".sge" , $sge_cmd);
			echo $this->c_cmds->cmds[$cmd_para["cmd_idx"]]."\n";
			echo shell_exec("qsub ".$this->sge_path."/sge_".$cmd_para["cmd_md5"].".sge");
			echo "\n";
		}
	}
};


class BenchMark extends BenchMarkRun
{
	//config
	
	//variable
	var $BM_cmds = array();
	
	//extends variables
	//	var $c_cmds;
	
	function BenchMark($prj_name)
	{
		$this->select_project($prj_name);
		@mkdir(dirname(__FILE__)."/{$this->log_path}");
		@mkdir(dirname(__FILE__)."/{$this->run_path}");
	}
	function select_project($prj_name)
	{
		$this->project_name = $prj_name;
		if(!isset($this->BM_cmds[$prj_name]))
		{
			$this->BM_cmds[$prj_name] = new BenchMarkCmd();
		}
		$this->c_cmds = &$this->BM_cmds[$this->project_name];
	}
	function add_parameter($name, $array)
	{
		$this->c_cmds->parameter[$name] = $array;
	}
	function run($cmd, $type="")
	{
		$this->c_cmds->make_cmds($cmd);
		switch($type)
		{
			case "sge":
				$this->sge_run();
				break;
			default:
				$this->default_run();
				break;
		}
	}
	
};


// example usage

$p_sbwt = "/home/andy/publish/sBWT/bin/sbwt_linux";
$p_genome = "/home/andy/andy/pokemon_0505/sbwt_test3/genome_hg19";
$p_reads = "/home/andy/andy/pokemon_0505/sbwt_test3/reads_hg19";
$p_index = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/index";
$p_log = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/log";
$p_result = "/home/andy/andy/pokemon_0505/sbwt_test3/sbwt/result";


$bb = new BenchMark("aa");
$bb->base_cpu = 6;
$bb->add_parameter("repeat", array(1) );
$bb->add_parameter("genome", array(1,2,4,8,16) );
$bb->add_parameter("interval", array(64) );
$cmd = "time $p_sbwt build -p $p_index/hg19_\$genomeX_test_400M_\$interval_\$repeat -i $p_genome/hg19_\$genomeX_test_400M.fa -s \$interval -f > ";
$cmd .= "$p_log/log_build_r_\$repeat_g_\$genome_i_\$interval.log 2>&1";
$bb->run($cmd, "sge");
exit();

exit();
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