<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

include_once PATH_THIRD.'cartthrob/config.php';
require_once PATH_THIRD.'cartthrob/fieldtypes/ft.cartthrob_matrix.php';

/**
 * @property CI_Controller $EE
 * @property Cartthrob_core_ee $cartthrob;
 * @property Cartthrob_cart $cart
 * @property Cartthrob_store $store
 */
class Cartthrob_price_modifiers_by_group_ft extends Cartthrob_matrix_ft
{
	public $info = array(
		'name' => 'CartThrob Price Modifiers by Member Group',
		'version' => "1.00"
	);
	
	public $default_row = array(
		'option_value' => '',
		'option_name' => '',
		'price' => '',
		'member_group'	=> '',
	);
	 
	public function display_field_member_group($name, $value, $row, $index, $blank = FALSE)
	{
		static $member_groups;
		$this->EE->load->helper('custom_field');
		
		if (is_null($member_groups))
		{
			$this->EE->load->model('member_model');
			
			// everything but guests, banned, and pending. 
			$query = $this->EE->member_model->get_member_groups(array(), array(array('group_id !=' => 2), array('group_id !=' => 3), array('group_id !=' => 4)));
			
			foreach ($query->result() as $row)
			{
				$member_groups[$row->group_id] = $row->group_title;
			}
		}
		$selected = decode_multi_field($value);
		
		return form_multiselect($name.'[]', $member_groups, $selected, 'dir="ltr" style="width:250px"');
 	}
	// this function pre-processes data before being output in the item-options dropdown
	public function item_options($data = array())
	{
		$return_data = array();
		foreach ($data as $key=> $value)
		{
  			if (!empty($value['member_group']) 
			 		&& is_array($value['member_group'])
					&& in_array($this->EE->session->userdata('group_id'), $value['member_group'] ))
			{
				$return_data[] = $value; 
 			}
		}
		return $return_data; 
	}
	public function pre_process($data)
	{
		$data = parent::pre_process($data);
		
		$this->EE->load->add_package_path(PATH_THIRD.'cartthrob/');
		
		$this->EE->load->library('cartthrob_loader');
		
		$this->EE->load->library('number');
		
		foreach ($data as $key => &$row)
		{
			// if this is not in your member group it will be removed.
			if (!empty($row['member_group']) 
  				&& is_array($row['member_group'])
				&& !in_array($this->EE->session->userdata('group_id'), $row['member_group'] ))
		 	{
				reset($data); 
				// for some reason EE was throwing errors if we tried to unset 
				if (array_key_exists($key, $data) === TRUE)
				{
					if (is_numeric($key))
					{
						array_splice($data, $key, 1);
					}
					else
					{
						unset($data[$key]); 
					}
				}
				continue ;
			}
			
			if (isset($row['price']) && $row['price'] !== '')
			{	
				$row['price_plus_tax']  = $row['price'];
 				
				if ($plugin = $this->EE->cartthrob->store->plugin($this->EE->cartthrob->store->config('tax_plugin')))
				{
					$row['price_plus_tax'] = $plugin->get_tax($row['price']) + $row['price'];
 				}
				
				$row['price_numeric'] = $row['price'];
				$row['price_plus_tax_numeric'] = $row['price:plus_tax_numeric'] = $row['price_numeric:plus_tax'] = $row['price_plus_tax'];
				
				$row['price'] = $this->EE->number->format($row['price']);
				$row['price_plus_tax'] = $row['price:plus_tax'] = $this->EE->number->format($row['price_plus_tax']);
			}
		}
		
		return $data;
	}
	
	public function replace_tag($data, $params = array(), $tagdata = FALSE)
	{
		if (isset($params['orderby']) && $params['orderby'] === 'price')
		{
			$params['orderby'] = 'price_numeric';
		}
		
		return parent::replace_tag($data, $params, $tagdata);
	}
	
	public function display_field($data, $replace_tag = FALSE)
	{
		$this->EE->lang->loadfile('cartthrob', 'cartthrob');
		
		$this->EE->load->library('cartthrob_loader');
		
		$this->EE->load->helper('html');
		
		if ( ! $presets = $this->EE->cartthrob->store->config('price_modifier_presets'))
		{
			$presets = array();
		}
		
		$options = array('' => $this->EE->lang->line('select_preset'));
		
		$json_presets = array();
		
		foreach ($presets as $key => $preset)
		{
			$json_presets[] = array(
				'name' => $key,
				'values' => $preset,
			);
			
			$options[] = $key;
		}
		
		$this->additional_controls = ul(
			array(
				form_dropdown('', $options),
				form_submit('', $this->EE->lang->line('load_preset'), 'onclick="$.cartthrobPriceModifiers.loadPreset($(this).parents(\'div.cartthrobMatrixControls\').prev(\'table.cartthrobMatrix\')); return false;"'),
				form_submit('', $this->EE->lang->line('delete_preset'), 'onclick="$.cartthrobPriceModifiers.deletePreset($(this).parents(\'div.cartthrobMatrixControls\').prev(\'table.cartthrobMatrix\')); return false;"'),
				form_submit('', $this->EE->lang->line('save_preset'), 'onclick="$.cartthrobPriceModifiers.savePreset($(this).parents(\'div.cartthrobMatrixControls\').prev(\'table.cartthrobMatrix\')); return false;"'),
			),
			array('class' => 'cartthrobMatrixPresets')
		);
		
		unset($this->default_row['inventory']);
		
		$channel_id = $this->EE->input->get('channel_id'); 

		if ( ! $channel_id  && isset($this->EE->safecracker))
		{
 			$channel_id = $this->EE->safecracker->channel('channel_id');
		}
		
		if ($channel_id && $this->field_id == $this->EE->cartthrob->store->config('product_channel_fields', $channel_id, 'inventory'))
		{
			$this->default_row['inventory'] = '';
		}
		
		if (empty($this->EE->session->cache['cartthrob_price_modifiers']['head']))
		{
			//always use action
			$url = (REQ === 'CP') ? 'EE.BASE+"&C=addons_modules&M=show_module_cp&module=cartthrob&method=save_price_modifier_presets_action"'
					     : 'EE.BASE+"ACT="+'.$this->EE->functions->fetch_action_id('Cartthrob_mcp', 'save_price_modifier_presets_action');
			
			$this->EE->cp->add_to_head('
			<script type="text/javascript">
			$.cartthrobPriceModifiers = {
				currentPreset: function(e) {
					return $(e).next("div.cartthrobMatrixControls").find("ul.cartthrobMatrixPresets select").val() || "";
				},
				presets: '.$this->EE->javascript->generate_json($json_presets, TRUE).',
				savePreset: function(e) {
					var currentValue = (this.presets[this.currentPreset(e)] !== undefined) ? this.presets[this.currentPreset(e)].name : "";
					var name = prompt("'.$this->EE->lang->line('name_preset_prompt').'", currentValue);
					if (name)
					{
						this.presets.push({"name": name, "values": $.cartthrobMatrix.serialize(e)});
						this.updatePresets();
					}
				},
				updatePresets: function() {
					var select = "<select>";
					select += "<option value=\'\'>'.$this->EE->lang->line('select_preset').'</option>";
					for (i in this.presets) {
						select += "<option value=\'"+i+"\'>"+this.presets[i].name+"</option>";
					}
					select += "</select>";
					$("div.cartthrobMatrixControls ul.cartthrobMatrixPresets select").replaceWith(select);
					$.post(
						'.$url.',
						{
							"XID": EE.XID,
							"price_modifier_presets": this.presets
						},
						function(data){
							EE.XID = data.XID;
						},
						"json"
					);
				},
				loadPreset: function(e) {
					var which = this.currentPreset(e);
					if (this.presets[which] != undefined && confirm("'.$this->EE->lang->line('load_preset_confirm').'")) {
						$.cartthrobMatrix.unserialize(e, this.presets[which].values);
					}
				},
				deletePreset: function(e) {
					var which = this.currentPreset(e);
					if (which && this.presets[which] != undefined && confirm("'.$this->EE->lang->line('delete_preset_confirm').'")) {
						delete this.presets[which];
						this.updatePresets();
					}
				}
			};
			</script>
			');
			
			$this->EE->session->cache['cartthrob_price_modifiers']['head'] = TRUE;
		}
		
		return parent::display_field($data, $replace_tag);
	}
}

/* End of file ft.cartthrob_discount.php */
/* Location: ./system/expressionengine/third_party/cartthrob_discount/ft.cartthrob_discount.php */