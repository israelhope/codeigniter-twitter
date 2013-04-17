#CodeIgniter-Twitter
##Modification
Twitter library ready to use 1.1 api version and Oauth 1.0a (Recomended for twitter)

by [Israel Rocha](https://twitter.com/israel_hope)

Add a new function to get header after call

    $this -> tweet -> get_header();

Now multicall fixed and working.
	
	$followers_ids = array(3829312, 12982039, 3432423, ...);
	$this->tweet->init_multicall();
	foreach($followers_ids as $id){
		// Some call to add.
		$this->tweet->call('post', 'direct_messages/new', array('user_id'=>$id, 'text'=>$message));
	}
	//Execute all calls. This Method return a array of all calls
	$responsesArray = $this->tweet->exec_multicall();
	// print_r($responsesArray)