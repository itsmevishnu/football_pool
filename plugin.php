<?php
/*
Plugin Name: fpm settings
Description: Modification in most used football tournament plugin. Now calculate the winners of prediction competition weekly or daily wise. The winner post type lists all the winners. 
Author: Vishnu Jayan
Version: 0.0.1
Text Domain: fpm
Author URI: http://vishnujayan.in
*/

if ( defined( ‘ABSPATH’ ) ) die('This is a plugin file!');

class fpmSettings {
    public function __construct() {
        add_action( 'init', array($this, 'fpm_winner_post_type'));
        add_action( 'init', array( $this, 'fpm_winner_tax') );
        add_action( 'plugins_loaded', array($this, 'seven_is_plugins_activated') );
        add_action( 'admin_menu', array( $this,'seven_winner_menu' ) );
        
        add_filter( 'wp_nav_menu_args', array( $this,'fpm_main_menu') );
        add_filter( 'wp_nav_menu_items', array( $this, 'fpm_logout_link') );
        add_filter( 'login_redirect', array( $this, 'seven_after_login'), 10, 3 );
        add_filter( 'wp_mail_from', array( $this,'seven_sender_email' ) );
        add_filter( 'wp_mail_from_name', array( $this,'seven_sender_name' ) );
        add_action( 'wp_head', array( $this, 'fpm_ga_code') );
        add_filter( 'manage_users_columns', array( $this,'fpm_add_user_columns' ) );
        add_filter( 'manage_users_custom_column', array( $this, 'fpm_add_user_column_data' ), 10, 3 );
        
        add_shortcode( 'daily-winner', array( $this,'seven_daily_winner' ), 10, 1 );
        add_shortcode( 'all-winners', array( $this,'seven_all_winners' )  );
        add_shortcode( 'leader-board', array($this, 'fpm_leader_board') );
    }

    /*Seperate Menus for logged and normal user*/

    public function fpm_main_menu( $args = "" ){
        if( is_user_logged_in() ) {
            $args['menu'] = 'main_menu_logged';
        } else {
            $args['menu'] = 'main_menu_normal';
        }
        return $args;
    }

    /*Logout link in menu **/

    public function fpm_logout_link( $items ) {
        if (  is_user_logged_in()) {
            $items .= '<li class="menu-item"><a href="'. wp_logout_url( home_url() ) .'">'. __("Log Out") .'</a></li>';
        }
        return $items;
    }

    /**Save winner to database */

    public function fpm_get_points() {
        global $current_user;
        global $wpdb;
        
        $pool = new Football_Pool_Pool();

        wp_get_current_user();

        $userleague = $pool->get_league_for_user( $current_user->ID );

        if ( ! isset( $userleague ) && ! is_integer( $userleague ) ) $userleague = FOOTBALLPOOL_LEAGUE_ALL;
        
        $league = apply_filters( 'footballpool_rankingpage_league', Football_Pool_Utils::request_string( 'league', $userleague ) );

        $ranking_display = Football_Pool_Utils::get_fp_option( 'ranking_display', 0 );

        if ( $ranking_display == 1 ) {
            $ranking_id = Football_Pool_Utils::request_int( 'ranking', FOOTBALLPOOL_RANKING_DEFAULT );
        } elseif ( $ranking_display == 2 ) {
            $ranking_id = Football_Pool_Utils::get_fp_option( 'show_ranking', FOOTBALLPOOL_RANKING_DEFAULT );
        } else {
            $ranking_id = FOOTBALLPOOL_RANKING_DEFAULT;
        }

        $rows = $pool->get_pool_ranking( $league, $ranking_id );

        return $rows;
    }

    /*Create winner post type */

    public function fpm_winner_post_type() {
        $args = array(
            'label'                 => __( 'Winners', 'fpm' ),
            'description'           => __( 'Winners of the contest', 'fpm' ),
            'supports'              => array( ),
            'hierarchical'          => false,
            'public'                => true,
            'show_ui'               => true,
            'show_in_menu'          => true,
            'menu_position'         => 5,
            'show_in_admin_bar'     => true,
            'show_in_nav_menus'     => true,
            'can_export'            => true,
            'has_archive'           => true,
            'exclude_from_search'   => false,
            'publicly_queryable'    => true,
            'capability_type'       => 'post',
            'menu_icon'             => 'dashicons-awards',
            'supports'              => array('title', 'editor', 'excerpt','revisions', 'custom-fields')
        );
        register_post_type( 'winner_post', $args );
    }
  
    public function fpm_winner_tax() {
        $args = array(
            'label'                     => 'Type',
            'hierarchical'               => true,
            'public'                     => true,
            'show_ui'                    => true,
            'show_admin_column'          => true,
            'show_in_nav_menus'          => true,
        );
        register_taxonomy( 'type', array( 'winner_post' ), $args );
    }
    /*Check the plugin activated*/

    public function seven_is_plugins_activated() {
        if ( ! class_exists( 'Football_Pool_Pool' )  ) {
           wp_die('Install football-pool', 'Error',array( 'back_link' => true ) );
        }
    }

    /**Menu options */
    public function seven_winner_menu() {
        if( ! current_user_can( 'list_users' ) ) wp_die("Sorry you have no permission to access this!");
        
        add_submenu_page('edit.php?post_type=winner_post',
         'Find Winner',
         'Find Winner',
         'manage_options',
         'find-winner',
         array( $this, 'fpm_find_winner_page') );

        add_submenu_page('edit.php?post_type=winner_post',
         'Calculate Weekly Points',
         'Calculate Weekly Points',
         'manage_options',
         'calculate-points',
         array( $this, 'fpm_calculate_weekly_points') );
    }

    public function fpm_find_winner_page () {
        if( isset($_POST['submit'])) {
            $winner = $this->fpm_save_winner();
            $winner_details = get_user_meta( $winner->ID);

            /**failure notice **/
            if( ! $winner ) {
                echo '<div class="notice notice notice-error is-dismissible">';
                echo '<p>some error occurred!</p>';
                echo '</div>';
                return;
            }
            /**Success notice **/
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>Done!, The Winner is added to Winners List!</p>';
            echo '</div>';
            //post values
            $defaults = array(
                'post_content' => 'Winner of the day<br>Prediction Contest',
                'post_title' => ucfirst( $winner_details['first_name'][0] ) . ' ' . ucfirst( $winner_details['last_name'][0]) ,
                'post_excerpt' => $winner->user_email,
                'post_status' => 'pending',
                'post_type' => 'winner_post',
            );

            $post_id = wp_insert_post( $defaults, false );
            //add to taxonomy
            wp_set_object_terms( $post_id, 'prediction', 'type', FALSE);

            //post extra values
            add_post_meta( $post_id, 'weekly_points', get_user_meta($winner->ID,'weekly_points', true) );
            add_post_meta( $post_id, 'points', get_user_meta($winner->ID,'points', true) );
            add_post_meta( $post_id, 'company', strtoupper( $winner_details['nickname'][0]) );
        }
        //form
        echo '<div class="wrap">';
        echo '<h1>Find the winner</h1>';
        echo '<form method="post" action="#">';
        submit_button('Find Winner');
        echo '</form>';
        echo '</div>';
    }

    /**Show weekly winner*/

    public function seven_daily_winner( $atts ) {
        $term = $atts['type'];
        $options = array(
                        'post_type' => 'winner_post',
                        'posts_per_page' => 1,
                        'orderby'=> 'date',
                        'order' => 'desc',
                        'post_status' => 'publish',
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'type',
                                'field'    => 'slug',
                                'terms'    => $term,
                            ),
                        ),
                    );
        $queryObject = new WP_Query( $options );

        $extra_details = get_post_meta($queryObject->post->ID, 'extra_details', true);
        $company = get_post_meta( $queryObject->post->ID, 'company', true );

        if ( $queryObject->have_posts() ) : $queryObject->the_post();
            $values = array(
                    'content' => $queryObject->post->post_content,
                    'name' => $queryObject->post->post_title,
                    'email' => $queryObject->post->post_excerpt,
                    'phone' => $extra_details? $extra_details['phone'] : '',
                    'company' => $company
                    );
            $html = $this->fpm_daily_html( $values );
        else:
            $html = '<div class="card"><h4><b> No winner right now!</b></h4></div>';
        endif;
        return $html;
    }

    /**List all the winners*/
    public function seven_all_winners( $atts ) {
        $term = $atts['type'];
        $options = array(
                        'post_type' => 'winner_post',
                        'posts_per_page' => -1,
                        'orderby'=> 'date',
                        'order' => 'desc',
                        'post_status' => 'publish',
                        'tax_query' => array(
                            array(
                                'taxonomy' => 'type',
                                'field'    => 'slug',
                                'terms'    => $term,
                            ),
                        ),
                    );
        $queryObject = new WP_Query( $options );
        return $this->seven_all_winner_table_html( $queryObject, $term );
    }

    //html formatting for daily winner
    public function fpm_daily_html( $values ) {
        $html = '<style>
                .card {
                    /* Add shadows to create the "card" effect */
                    box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
                    transition: 0.3s;
                }
                .title,.img{
                    padding: 2px 16px;
                    text-align: center;
                }
                /* On mouse-over, add a deeper shadow */
                .card:hover {
                    box-shadow: 0 8px 16px 0 rgba(0,0,0,0.2);
                }

                /* Add some padding inside the card container */
                .container {
                    padding: 2px 16px;
                }</style>';
        $html .= ' <div class="card"> ';
        $html .= '<div class="title"><h4><b>'.  $values['content'] .'</b></h4></div>';
        $html .= '<div class="img" ><img src="'.plugin_dir_url( $file ) .'trophy.png" alt="fpm" style="width:40%;"></div>';
        $html .= '<div class="container">';
        $html .= '<h4><b>'. $values['name'] .'</b></h4>';
        $html .= '<p>'. $values['email'] . '</p>';
        $html .= '<p>'. $values['phone']. '</p>';
        $html .= '<p>'. $values['company']. '</p>';
        $html .= '</div>';
        $html .= '</div> ';
        return $html;
    }

    /**list all the winners **/
    public function seven_all_winner_table_html( $queryObject, $item ){

        if ( $queryObject->have_posts() ) :

        $thead = "prediction" == $item ? '<th>#</th><th>Date</th><th>Name</th><th>Company</th><th>Weekly Points</th>' : '<th>#</th><th>Date</th><th>Name</th><th>Company</th>';

        $i = 1;


            $html = '<table class="matchinfo">';
            $html.= "<tr>" . $thead . "</tr>";

            while($queryObject->have_posts() ): $queryObject->the_post();

            $extra_details = get_post_meta($queryObject->post->ID, 'weekly_points', true);
            $company = get_post_meta( $queryObject->post->ID, 'company', true );
            $company= $company? $company : 'NA';
            $points = $extra_details ? '<td>' . $extra_details . '</td>' : '';

            $html .= "<tr>";
            $html .= '<td>' . $i . '</td>';
            $html .= '<td>' . get_the_date('d/m/Y') . '</td>';
            $html .= '<td>' . $queryObject->post->post_title . '</td>';
            $html .= '<td>' . $company  . '</td>';
            $html .= $points;
            $html .= "</tr>";
            $i ++;

            endwhile;

            $html .= "</table>";
            $html .= "<style> .matchinfo td {text-align:center}</style>";
        else:
            $html = '<div class="card"><h4><b> No winner right now!</b></h4></div>';
        endif;
        return $html;
    }
  
    /*change the login url*/
  
    public function seven_after_login( $redirect_to, $request, $user) {
        $role_name = $user->roles[0];
        if ( 'subscriber' === $role_name ) {
          $redirect_to = home_url('/pool/ ') ;
        }
        return $redirect_to;
    }
  
    /**change email */
  
    public function seven_sender_email( $original_email_address ){
        return 'nonreply@fpm.prathidhwani.org';
    }
    public function seven_sender_name( $original_email_from ) {
        return 'Administrator';
    }

    /*GA code*/
    public function fpm_ga_code() {
        ?>
        <script>
            jQuery(document).ready(function($){
                $('#matchinfo-1 td:last-child').remove();
                $('#matchinfo-1 td:last-child').remove();
                $('span.username').css('color','#787878');
                $('span.username+p').remove();
            });

              (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
              (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
              m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
              })(window,document,'script','https://www.google-analytics.com/analytics.js','ga');

              ga('create', 'UA-102523565-1', 'auto');
              ga('send', 'pageview');

        </script>
        <?php
    }

    /* Calculates weeklypoints
      Current point - prev_points
     */
  
    public function fpm_weekly_point_calculation( $winner ) {
        $weekly_points = get_user_meta($winner['user_id'],'weekly_points', true);
        $prev_points = get_user_meta($winner['user_id'],'prev_points', true);
        if( $prev_points !=  $winner['points'] ) {
          $weekly_points = $winner['points'] - $prev_points;
          $prev_points = $winner['points'];

          update_user_meta( $winner['user_id'], 'points', $winner['points'] );
          update_user_meta( $winner['user_id'], 'prev_points', $prev_points );
          update_user_meta( $winner['user_id'], 'weekly_points', $weekly_points );
        }

    }
  
    /* Add user columns */
  
    public function fpm_add_user_columns( $column ) {
        $column['weekly_score'] = 'Weekly score';
        $column['prev_score'] = 'Previous score';
        $column['total_score'] = 'Total score';
        return $column;
    }

    /**add values to the column in user.php*/
  
    public function fpm_add_user_column_data($val, $column_name, $user_id) {
        $total_score = get_user_meta($user_id,'points', true);
        $prev_score = get_user_meta($user_id,'prev_points', true);
        $weekly_score = get_user_meta($user_id,'weekly_points', true);
        switch ($column_name) {
            case 'weekly_score' :
                $output = $weekly_score;
                break;
            case 'prev_score' :
                $output .= $prev_score;
              break;
            case 'total_score' :
                $output .= $total_score;
                break;
        }
        return  $output;
    }

    /*calculate the points for the users*/
  
    public function fpm_calculate_weekly_points() {
        if( isset($_POST['submit'])) {

            $winners = $this->fpm_get_points();
            foreach ($winners as $winner) {
                $this->fpm_weekly_point_calculation( $winner );
            }

            /**failure notice **/
            if( ! $winners ) {
                echo '<div class="notice notice notice-error is-dismissible">';
                echo '<p>some error occurred!</p>';
                echo '</div>';
                return;
            }
            /**Success notice **/
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p>Done!, Weekly points added!</p>';
            echo '</div>';

        }
        //form
        echo '<div class="wrap">';
        echo '<h1>Calculate weekly score</h1>';
        echo '<form method="post" action="#">';
        submit_button('Calculate');
        echo '</form>';
        echo '</div>';
    }

    /*calculate the winner*/
  
    public function fpm_save_winner() {
        $args = array(
                'orderby' => 'meta_value_num',
                'meta_key' => 'weekly_points',
                'order' => 'desc',
                'number' => 1
        );
        $user_query = new WP_User_Query($args);

        return $user_query->get_results()[0];
    }
  
    /*
    * find the leader board
    */
  
    public function fpm_leader_board() {
        $args = array(
                'orderby' => 'meta_value_num',
                'meta_key' => 'points',
                'order' => 'desc',
                'number' => 10
        );
        $user_query = new WP_User_Query($args);
        if( $user_query->results ) :
        $html = '<table class="matchinfo">';
        $html .= '<th>#</th><th>Name</th><th>Company</th><th>Total Points</th>';

        $i = 1;

        foreach($user_query->results as $winner) :
        $winner_details = get_user_meta( $winner->ID);

        $name = ucfirst( $winner_details['first_name'][0] ) . ' ' . ucfirst( $winner_details['last_name'][0]);
        $company = strtoupper($winner_details['nickname'][0]);
        $total_points = get_user_meta($winner->ID,'points', true);

        $html .= '<tr>';
        $html .= "<td>{$i}</td><td>{$name}</td><td>{$company}</td><td>{$total_points}</td>";
        $html .= '</tr>';

        $i ++;
        endforeach;
        endif;
        $html .= "</table>";
        $html .= "<style> .matchinfo td {text-align:center}</style>";
        return $html;
    }
}

$object = new fpmSettings();
