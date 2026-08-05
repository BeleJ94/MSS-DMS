<?php
$classes = ['Brouillon'=>'neutral','À préparer'=>'warning','Prête'=>'success','Chargement'=>'info','Chargée'=>'info','Partie'=>'info','Arrivée'=>'info','Livrée'=>'success','Clôturée'=>'success','Incident'=>'danger','En transit'=>'info','Planifiée'=>'neutral','En attente'=>'warning','Annulée'=>'danger','Actif'=>'success','Inactif'=>'neutral','Prospect'=>'info','Archivé'=>'danger','Disponible'=>'success','Affecté'=>'info','En mission'=>'info','En livraison'=>'info','Maintenance'=>'warning','Indisponible'=>'warning','Terminée'=>'success','Ouvert'=>'danger','Résolu'=>'success'];
$class = $classes[$status] ?? 'neutral';
?>
<span class="status-badge <?= $class ?>"><i></i><?= htmlspecialchars($status, ENT_QUOTES, 'UTF-8') ?></span>
